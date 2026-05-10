<?php
declare(strict_types=1);

/** @var string $easaApiHref */
/** @var string $easaUserPhotoHref URL for current admin user profile photo (empty if none). */
/** @var string $easaMayaAvatarHref Maya avatar URL (broken/missing files use onerror fallback in the UI). */

if (!isset($easaApiHref) || $easaApiHref === '') {
    $easaApiHref = '/admin/api/resource_library_easa_api.php';
}
if (!isset($easaUserPhotoHref)) {
    $easaUserPhotoHref = '';
}
if (!isset($easaMayaAvatarHref) || $easaMayaAvatarHref === '') {
    $easaMayaAvatarHref = '/assets/avatars/maya.png';
}
?>
<style>
  .rl-easa-page {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
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
    min-height: 420px;
    max-height: min(88vh, 1400px);
    overflow: auto;
    background: #fafbfc;
    font-size: 13px;
    width: 100%;
    box-sizing: border-box;
  }
  @media (max-width: 520px) {
    .rl-easa-tree-panel {
      min-height: 300px;
    }
  }
  .rl-easa-tree-loading-msg {
    font-size: 13px;
    color: #64748b;
    padding: 0.75rem 0;
  }
  .rl-easa-tree-loading-center {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    min-height: 240px;
    padding: 24px 12px;
    color: #64748b;
    font-size: 13px;
    text-align: center;
  }
  .rl-easa-tree-spinner {
    width: 22px;
    height: 22px;
    border: 2px solid #e2e8f0;
    border-top-color: #102845;
    border-radius: 50%;
    animation: rl-easa-tree-spin 0.75s linear infinite;
  }
  @keyframes rl-easa-tree-spin {
    to { transform: rotate(360deg); }
  }
  .rl-easa-tree-empty {
    max-width: 28rem;
    margin: 0 auto;
    line-height: 1.45;
  }
  .rl-easa-tree-reveal-full {
    min-height: 200px;
  }
  .rl-easa-tree-reveal-full .rl-easa-tree-reveal-pct,
  .rl-easa-tree-reveal-status .rl-easa-tree-reveal-pct {
    font-size: 12px;
    font-weight: 700;
    color: #102845;
    font-variant-numeric: tabular-nums;
  }
  .rl-easa-tree-reveal-status {
    padding: 6px 10px 8px;
    margin: 0 0 8px;
    border-radius: 8px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    font-size: 12px;
    color: #475569;
    line-height: 1.35;
  }
  .rl-easa-tree-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 12px 16px;
    margin-bottom: 10px;
  }
  .rl-easa-tree-batch-label {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin: 0;
  }
  .rl-easa-tree-batch-select {
    min-height: 38px;
    max-width: 320px;
    width: 100%;
    font-size: 13px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 6px 10px;
    background: #fff;
    font-family: inherit;
  }
  @media (max-width: 520px) {
    .rl-easa-tree-toolbar {
      flex-direction: column;
      align-items: stretch;
    }
    .rl-easa-tree-batch-select {
      max-width: none;
    }
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
    background: rgba(16, 40, 69, 0.08);
    border-radius: 6px;
    margin-left: -4px;
    margin-right: -4px;
    padding-left: 4px;
    padding-right: 4px;
  }
  .rl-easa-tree-row {
    display: flex;
    align-items: center;
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
    color: #102845;
    font-size: 12px;
    width: 1.25rem;
    line-height: 1;
    height: 1.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
  }
  /* Semantic API: rule + expandable=true (IR with AMC/GM children only) — slightly smaller disclosure */
  .rl-easa-tree-exp--rule-disclosure {
    font-size: 10px;
    line-height: 1;
    height: 1.1rem;
    width: 1.1rem;
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
    margin-left: 2.75rem;
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
    color: #102845;
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
    color: #102845;
  }
  .rl-easa-tree-rule-supplement {
    font-style: italic;
    color: #334155;
  }
  .rl-easa-tree-rule-supplement:hover {
    color: #102845;
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
  /* IR / default tree accents — hero navy (#102845), not bright UI blue */
  .rl-easa-band-ir { background: linear-gradient(90deg, #0c2744, #102845); }
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
    margin-top: 0;
    margin-right: 2px;
  }
  .rl-easa-tree-dot-ir { background: #102845; }
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
  .rl-easa-ai-session-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin: 10px 0 12px;
  }
  .rl-easa-ai-session-bar label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
  }
  .rl-easa-chat-history {
    margin-top: 12px;
    max-height: 340px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px;
    background: #f8fafc;
  }
  .rl-easa-chat-history:empty {
    display: none;
  }
  .rl-easa-hist-block {
    margin-bottom: 12px;
  }
  .rl-easa-hist-block:last-child {
    margin-bottom: 0;
  }
  .rl-easa-hist-cards {
    margin-top: 8px;
  }
  .rl-easa-ai-excerpt-modal .rl-easa-modal-dialog {
    max-width: min(920px, 96vw);
    max-height: min(90vh, 1200px);
  }
  .rl-easa-ai-excerpt-body {
    max-height: min(70vh, 800px);
    overflow: auto;
    font-size: 13px;
    line-height: 1.5;
  }
  .rl-easa-ai-excerpt-meta {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 10px;
    word-break: break-word;
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
    width: min(760px, 100%);
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
  .rl-easa-modal-head-text {
    min-width: 0;
    flex: 1;
  }
  .rl-easa-modal-head h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
  }
  .rl-easa-modal-intro {
    margin: 8px 0 0;
    font-size: 13px;
    color: #64748b;
    line-height: 1.5;
    font-weight: 400;
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

  /* —— Maya chat (AI layer only; scoped) —— */
  .rl-easa-maya-chat {
    display: flex;
    flex-direction: column;
    gap: 0;
    min-height: 0;
  }
  .rl-easa-maya-chat--primary {
    box-shadow: 0 4px 24px rgba(16, 40, 69, 0.08);
    border: 1px solid #dbe3ee;
  }
  .rl-easa-maya-chat-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
  }
  .rl-easa-maya-chat-title-block {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 10px 14px;
    min-width: 0;
    flex: 1;
  }
  .rl-easa-maya-chat-settings-slot:empty {
    display: none;
  }
  .rl-easa-maya-chat-header h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.3;
    max-width: 100%;
  }
  .rl-easa-maya-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
    min-height: 32px;
  }
  .rl-easa-maya-loadmore {
    text-align: center;
    padding: 6px 0 10px;
  }
  .rl-easa-maya-loadmore[hidden] { display: none !important; }
  .rl-easa-maya-loadmore button {
    font-size: 11px;
    color: #475569;
    background: transparent;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    padding: 3px 12px;
    cursor: pointer;
  }
  .rl-easa-maya-loadmore button[disabled] {
    opacity: 0.6;
    cursor: progress;
  }
  .rl-easa-maya-lead {
    margin: 0 0 12px;
    font-size: 12px;
    color: #475569;
    line-height: 1.5;
  }
  .rl-easa-maya-thread {
    flex: 1;
    min-height: 240px;
    max-height: min(56vh, 580px);
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 10px;
    margin: 0 0 12px;
    border-radius: 14px;
    background: linear-gradient(180deg, #f1f5f9 0%, #e8eef5 100%);
    border: 1px solid #e2e8f0;
    -webkit-overflow-scrolling: touch;
  }
  .rl-easa-maya-msg-row {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
    align-items: flex-start;
  }
  .rl-easa-maya-msg-row--user {
    flex-direction: row-reverse;
    justify-content: flex-start;
  }
  .rl-easa-maya-msg-row--maya {
    flex-direction: row;
    justify-content: flex-start;
  }
  .rl-easa-maya-bubble-wrap {
    max-width: min(88%, 640px);
    flex: 0 1 auto;
  }
  .rl-easa-maya-msg-row--user .rl-easa-chat-bubble-user {
    border-radius: 18px 18px 4px 18px;
    max-width: 100%;
  }
  .rl-easa-maya-msg-row--maya .rl-easa-chat-bubble-system {
    border-radius: 18px 18px 18px 4px;
    max-width: 100%;
  }
  .rl-easa-maya-avatar {
    flex: 0 0 36px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    font-weight: 800;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.1);
    user-select: none;
    overflow: hidden;
  }
  .rl-easa-maya-avatar img.rl-easa-maya-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 50%;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
  }
  .rl-easa-maya-avatar--has-img .rl-easa-maya-avatar-letter,
  .rl-easa-maya-avatar--has-img .rl-easa-maya-avatar-user-fallback {
    display: none;
  }
  .rl-easa-maya-avatar--img-broken .rl-easa-maya-avatar-img {
    display: none !important;
  }
  .rl-easa-maya-avatar--img-broken .rl-easa-maya-avatar-letter {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    font-weight: 800;
    font-size: 14px;
    color: #475569;
  }
  .rl-easa-maya-avatar--img-broken .rl-easa-maya-avatar-user-fallback {
    display: block;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: linear-gradient(135deg, #cbd5e1, #94a3b8);
  }
  .rl-easa-maya-msg-row--maya .rl-easa-maya-avatar {
    background: #e2e8f0;
  }
  .rl-easa-maya-avatar.is-thinking {
    animation: rl-easa-maya-glow 1.6s ease-in-out infinite;
  }
  @keyframes rl-easa-maya-glow {
    0%, 100% { box-shadow: 0 0 0 rgba(16, 40, 69, 0); }
    50% { box-shadow: 0 0 16px rgba(16, 40, 69, 0.35); }
  }
  .rl-easa-maya-msg-stack {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    max-width: min(88%, 640px);
  }
  .rl-easa-maya-msg-row--user .rl-easa-maya-msg-stack {
    align-items: flex-end;
  }
  .rl-easa-maya-avatar--user-fallback {
    background: linear-gradient(135deg, #cbd5e1, #94a3b8);
  }
  .rl-easa-maya-msg-row--maya .rl-easa-maya-msg-stack {
    align-items: flex-start;
  }
  .rl-easa-maya-msg-time {
    font-size: 10px;
    color: #94a3b8;
    line-height: 1.2;
    padding: 0 2px;
    font-variant-numeric: tabular-nums;
  }
  .rl-easa-maya-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
    align-items: center;
  }
  .rl-easa-maya-chat .rl-easa-chat-bubble {
    padding: 8px 10px;
    font-size: 14px;
    line-height: 1.45;
  }
  .rl-easa-maya-chat .rl-easa-chat-meta {
    font-size: 10px;
    margin-bottom: 3px;
  }
  .rl-easa-maya-chat .rl-easa-maya-msg-body {
    font-size: 13px;
    line-height: 1.45;
  }
  .rl-easa-maya-chat .rl-easa-maya-msg-body p {
    font-size: 13px;
  }
  .rl-easa-maya-msg-stack .rl-easa-maya-bubble-wrap {
    max-width: 100%;
    width: 100%;
  }
  .rl-easa-maya-chip-label {
    font-size: 11px;
    font-weight: 700;
    color: #0f172a;
    background: #e0e7ff;
    border: 1px solid #c7d2fe;
    border-radius: 999px;
    padding: 6px 12px;
    max-width: 100%;
    line-height: 1.3;
  }
  .rl-easa-maya-chip-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .rl-easa-maya-compose {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-top: 4px;
  }
  .rl-easa-maya-compose textarea {
    flex: 1 1 200px;
    min-height: 44px;
    max-height: 140px;
    resize: vertical;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    padding: 8px 10px;
    font-size: 13px;
    line-height: 1.45;
    font-family: inherit;
    width: 100%;
    box-sizing: border-box;
  }
  .rl-easa-maya-send {
    flex: 0 0 auto;
    border-radius: 12px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: 12px;
  }
  .rl-easa-maya-chat .rl-easa-chat-bubble {
    max-width: 100%;
  }
  .rl-easa-maya-chat .rl-easa-chat-bubble-system h2,
  .rl-easa-maya-chat .rl-easa-chat-bubble-system h3 {
    margin: 0.4rem 0 0.35rem;
    font-size: 0.95rem;
    font-weight: 800;
    color: #0f172a;
  }
  .rl-easa-maya-excerpt-band {
    border-radius: 10px 10px 0 0;
    padding: 10px 12px;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    line-height: 1.35;
  }
  .rl-easa-maya-excerpt-band small {
    display: block;
    font-weight: 500;
    font-size: 11px;
    opacity: 0.92;
    margin-top: 4px;
  }
  .rl-easa-maya-excerpt-wrap {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
  }
  .rl-easa-maya-excerpt-tech {
    margin: 10px 12px 0;
    font-size: 12px;
    color: #64748b;
  }
  .rl-easa-maya-excerpt-tech pre {
    margin: 6px 0 0;
    font-size: 11px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 120px;
    overflow: auto;
    background: #f8fafc;
    padding: 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
  }
  @media (min-width: 521px) {
    .rl-easa-maya-chat .rl-easa-chat-bubble {
      font-size: 12.25px;
    }
  }
  @media (max-width: 520px) {
    .rl-easa-maya-thread { max-height: min(68vh, 620px); }
    .rl-easa-maya-compose { flex-direction: column; align-items: stretch; gap: 10px; }
    .rl-easa-maya-compose textarea {
      min-height: 88px;
      padding: 12px 12px;
      font-size: 16px;
      line-height: 1.45;
    }
    .rl-easa-maya-send { width: 100%; padding: 12px 18px; font-size: 14px; }
  }
  .rl-easa-maya-chat-sentinel {
    height: 1px;
    margin: 0;
    padding: 0;
    pointer-events: none;
  }
  .rl-easa-maya-load-earlier-fallback {
    text-align: center;
    padding: 6px 0 10px;
  }
</style>

<div class="rl-wrap rl-tab-panel rl-easa-page" id="rlEasaPage" data-api="<?= h($easaApiHref) ?>" data-user-photo="<?= h($easaUserPhotoHref) ?>" data-maya-avatar="<?= h($easaMayaAvatarHref) ?>">
  <div class="rl-easa-metrics" id="rlEasaMetrics" aria-live="polite">
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">XML batches</div><div class="rl-easa-metric-value" id="rlEasaMetricBatches">—</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Indexed nodes</div><div class="rl-easa-metric-value" id="rlEasaMetricNodes">—</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Monitored URLs</div><div class="rl-easa-metric-value" id="rlEasaMetricMon">—</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Updates flagged</div><div class="rl-easa-metric-value" id="rlEasaMetricUpdates">—</div><div class="rl-easa-metric-sub">Download page monitor</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Last probe (UTC)</div><div class="rl-easa-metric-value" id="rlEasaMetricLastProbe" style="font-size:1rem;">—</div></div>
  </div>

  <section class="card rl-easa-dash-panel rl-easa-maya-chat rl-easa-maya-chat--primary" style="padding:16px 18px; margin-bottom:14px;">
    <div class="rl-easa-maya-chat-header">
      <div class="rl-easa-maya-chat-title-block">
        <h3>Ask Maya, your Regulations Assistant</h3>
        <span class="rl-easa-maya-chat-settings-slot" id="rlEasaMayaChatSettingsSlot" aria-hidden="true"></span>
      </div>
      <div class="rl-easa-maya-header-actions" aria-hidden="true"></div>
    </div>
    <p class="rl-easa-dash-lead rl-easa-maya-lead">
      Maya helps you navigate EASA Easy Access Rules in plain language. Ask naturally — for a U.S. comparison, say something like &ldquo;How does this compare to the FAA rules?&rdquo; Not legal advice; always confirm on official publications.
    </p>
    <div class="rl-easa-maya-thread" id="rlEasaChatHistory" aria-label="Chat with Maya"></div>
    <p class="rl-drop-meta" id="rlEasaChatPersistHint" style="margin:6px 2px 0;font-size:11px;"></p>
    <div class="rl-easa-maya-compose">
      <textarea id="rlEasaChatQ" rows="2" placeholder="Ask Maya anything about the regulations…" aria-label="Message to Maya"></textarea>
      <button type="button" class="btn btn-sm rl-easa-maya-send" id="rlEasaChatSendBtn">Send</button>
    </div>
    <input type="hidden" id="rlEasaChatUseAi" value="1">
  </section>

  <section class="card rl-easa-dash-panel rl-easa-tree-dash" id="rlEasaTreeSection" style="padding:16px 18px; margin-bottom:14px;">
    <h3>Live Easy Access Rules</h3>
    <p class="rl-easa-dash-lead">Browse the official Easy Access text as indexed on this server. Pick a regulation set, then open sections in the tree and read the published wording beside it.</p>
    <div class="rl-easa-tree-toolbar">
      <label class="rl-easa-tree-batch-label" for="rlEasaTreeBatch">Select your EASA Easy Access Rules</label>
      <select id="rlEasaTreeBatch" class="rl-easa-tree-batch-select" aria-label="Select EASA Easy Access Rules">
        <option value="">Loading…</option>
      </select>
    </div>
    <div class="rl-easa-browse-single">
      <p class="rl-drop-meta" id="rlEasaTreeHint" style="margin:0 0 8px;">Loading regulations…</p>
      <div class="rl-easa-tree-panel" id="rlEasaTreeMount" aria-label="Easy Access rule tree">
        <div class="rl-easa-tree-loading-center" role="status">
          <div class="rl-easa-tree-spinner" aria-hidden="true"></div>
          <span>Loading regulations…</span>
        </div>
      </div>
    </div>
  </section>
</div>

<div class="rl-easa-modal-overlay" id="rlEasaSourceModal" hidden>
  <div class="rl-easa-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rlEasaModalTitle">
    <div class="rl-easa-modal-head">
      <div class="rl-easa-modal-head-text">
        <h2 id="rlEasaModalTitle">Easy Access Rules — sources</h2>
        <p class="rl-easa-modal-intro">Manage official EASA Easy Access XML on this server, check EASA download pages for updates, and control which sources are published as live for instructors and students.</p>
      </div>
      <button type="button" class="rl-easa-modal-close" id="rlEasaModalClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-easa-modal-body">
      <div class="rl-easa-modal-section" id="rlEasaModalSectionIdentity">
        <h3>Current source</h3>
        <div id="rlEasaModalIdentityBody" class="rl-drop-meta" style="margin:0;line-height:1.5;"></div>
      </div>
      <div class="rl-easa-modal-section">
        <h3>Upload official XML</h3>
        <div class="rl-easa-dropzone" id="rlEasaDropzone" tabindex="0">
          <p><strong>Drop a file</strong> or click to choose the official Easy Access export. Files are stored securely on the server under <code>storage/easa_erules/</code>.</p>
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
        <h3>Parse into the index</h3>
        <p class="rl-drop-meta" style="margin:0 0 8px;">The server reads the XML and builds the searchable rule index used by Maya and the live rule tree. Large files are processed in the background; you can watch progress here.</p>
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
        <h3>Download page checks</h3>
        <p class="rl-drop-meta" style="margin:0 0 8px;">Optional checks against the official EASA download pages to see if a newer Easy Access package may be available. Run a check manually or rely on your server’s scheduled job.</p>
        <div class="rl-panel-actions">
          <button type="button" class="btn btn-sm" id="rlEasaProbeBtn">Check for updates now</button>
        </div>
        <p class="rl-drop-meta rl-easa-modal-footnote">All listed URLs share the same monitoring schedule. Contact your hosting team if you need different intervals per source.</p>
      </div>
      <div class="rl-easa-modal-section">
        <h3>Publishing as “live”</h3>
        <label class="rl-check-row" style="display:flex;gap:8px;align-items:flex-start;opacity:0.55;">
          <input type="checkbox" id="rlEasaModalLiveToggle" disabled style="margin-top:3px;">
          <span class="rl-check-label">Mark this source as the live regulation set for the platform (coming soon — requires catalogue wiring).</span>
        </label>
        <p class="rl-drop-meta" style="margin:8px 0 0;">Until then, use the <strong>Live Easy Access Rules</strong> list on the main page to choose which indexed XML instructors browse.</p>
      </div>
      <div class="rl-easa-modal-section rl-easa-modal-section--admin-tables">
        <h3>Technical overview</h3>
        <p class="rl-drop-meta" id="rlEasaMigrateHint" style="margin-top:0;"></p>
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
        <p class="rl-drop-meta" style="margin-top:14px;font-weight:700;">Indexed batches</p>
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
      </div>
    </div>
  </div>
</div>

<div class="rl-easa-modal-overlay rl-easa-ai-excerpt-modal" id="rlEasaAiExcerptModal" hidden>
  <div class="rl-easa-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rlEasaAiExcerptTitle">
    <div class="rl-easa-modal-head">
      <h2 id="rlEasaAiExcerptTitle">Regulation excerpt</h2>
      <button type="button" class="rl-easa-modal-close" id="rlEasaAiExcerptClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-easa-modal-body">
      <div class="rl-easa-maya-excerpt-wrap">
        <div class="rl-easa-maya-excerpt-band rl-easa-inline-band rl-easa-band rl-easa-band-neu" id="rlEasaAiExcerptBand">Loading…</div>
        <details class="rl-easa-maya-excerpt-tech">
          <summary>Technical details</summary>
          <pre id="rlEasaAiExcerptTechPre"></pre>
        </details>
        <div class="rl-panel-actions" style="margin:10px 12px 12px;">
          <button type="button" class="btn btn-sm" id="rlEasaAiExcerptOpenTree">Open this section in the tree</button>
        </div>
        <div class="rl-easa-ai-excerpt-body rl-easa-detail-body" id="rlEasaAiExcerptBody"></div>
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
  var rlEasaAiSessionId = 0;
  var rlEasaAiExcerptState = { batchId: 0, nodeUid: '', terms: [] };
  var rlEasaMayaThinkingTimers = [];
  var rlEasaUserPhoto = (root && root.getAttribute('data-user-photo')) ? String(root.getAttribute('data-user-photo')).trim() : '';
  var rlEasaMayaAvatarSrc = (function () {
    var u = (root && root.getAttribute('data-maya-avatar')) ? String(root.getAttribute('data-maya-avatar')).trim() : '';
    return u || '/assets/avatars/maya.png';
  })();
  /** Latest batch rows from /status, keyed by id — used for corpus label checks (default tree open). */
  var rlEasaStatusBatchesById = {};
  /**
   * Placeholder for a future “page settings” modal. Hardcoded defaults: Aircrew + Part-FCL + ANNEX I expanded.
   * expandPathTitleRegex: title match on each level (section or rule row), in order.
   */
  var rlEasaTreeDefaultOpenConfig = {
    enabled: true,
    corpusShortLabel: 'Aircrew',
    expandPathTitleRegex: [/\bPART[\s.-]*FCL\b/i, /\bANNEX\s*I\b/i]
  };
  var rlEasaMayaChatIo = null;
  var RL_EASA_TREE_LOADING_HTML = '<div class="rl-easa-tree-loading-center" role="status"><div class="rl-easa-tree-spinner" aria-hidden="true"></div><span>Loading regulations…</span></div>';
  /** When true, <select id="rlEasaTreeBatch"> change events from programmatic selection are ignored. */
  var rlEasaTreeBatchSilent = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] || c;
    });
  }

  function rlEasaAiHighlightTerms(rootEl, terms) {
    if (!rootEl || !Array.isArray(terms)) return;
    terms.forEach(function (t) {
      var n = String(t || '').trim();
      if (n.length >= 2) rlEasaHighlightInTextNodes(rootEl, n);
    });
  }

  function rlEasaFormatChatTime(s) {
    if (s == null || s === '') return '';
    var d;
    if (typeof s === 'number') {
      d = new Date(s);
    } else if (s instanceof Date) {
      d = s;
    } else {
      var raw = String(s).trim();
      if (!raw) return '';
      if (/^\d{10,13}$/.test(raw)) {
        d = new Date(parseInt(raw, 10));
      } else if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/.test(raw)
          && !(/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw))) {
        d = new Date(raw.replace(' ', 'T') + 'Z');
      } else {
        d = new Date(raw.replace(' ', 'T'));
      }
    }
    if (!d || isNaN(d.getTime())) return '';
    try {
      if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' }).format(d);
      }
    } catch (e) { /* Safari/WebKit can throw "The string did not match the expected pattern" for unsupported option combos */ }
    try {
      return d.toLocaleString();
    } catch (e2) {
      return '';
    }
  }

  function rlEasaMsgTimestampField(row) {
    if (!row || typeof row !== 'object') return '';
    var iso = row.created_at_iso;
    if (iso != null && String(iso).trim() !== '') return String(iso).trim();
    return row.created_at || '';
  }

  /** Build avatar DOM (avoids innerHTML/onerror quirks and keeps img.src as a raw URL). */
  function rlEasaMayaBuildAvatarEl(isMaya, thinkingClass) {
    var div = document.createElement('div');
    div.className = 'rl-easa-maya-avatar' + (thinkingClass ? ' ' + thinkingClass : '');
    div.setAttribute('aria-hidden', 'true');
    function bindImgErr(img) {
      img.addEventListener('error', function () {
        try {
          img.style.display = 'none';
          if (img.parentNode) img.parentNode.classList.add('rl-easa-maya-avatar--img-broken');
        } catch (e) { /* ignore */ }
      }, { once: true });
    }
    if (isMaya) {
      var msrc = String(rlEasaMayaAvatarSrc || '').trim() || '/assets/avatars/maya.png';
      div.classList.add('rl-easa-maya-avatar--has-img');
      var mimg = document.createElement('img');
      mimg.className = 'rl-easa-maya-avatar-img';
      mimg.alt = '';
      mimg.width = 36;
      mimg.height = 36;
      mimg.decoding = 'async';
      mimg.loading = 'lazy';
      mimg.src = msrc;
      bindImgErr(mimg);
      var letter = document.createElement('span');
      letter.className = 'rl-easa-maya-avatar-letter';
      letter.setAttribute('aria-hidden', 'true');
      letter.textContent = 'M';
      div.appendChild(mimg);
      div.appendChild(letter);
      return div;
    }
    var up = String(rlEasaUserPhoto || '').trim();
    if (up) {
      div.classList.add('rl-easa-maya-avatar--has-img', 'rl-easa-maya-avatar--user-img');
      var uimg = document.createElement('img');
      uimg.className = 'rl-easa-maya-avatar-img';
      uimg.alt = '';
      uimg.width = 36;
      uimg.height = 36;
      uimg.decoding = 'async';
      uimg.loading = 'lazy';
      uimg.src = up;
      bindImgErr(uimg);
      var ufb = document.createElement('span');
      ufb.className = 'rl-easa-maya-avatar-user-fallback';
      ufb.setAttribute('aria-hidden', 'true');
      div.appendChild(uimg);
      div.appendChild(ufb);
      return div;
    }
    div.classList.add('rl-easa-maya-avatar--img-broken', 'rl-easa-maya-avatar--user-fallback');
    return div;
  }

  function rlEasaMayaClearThinkingTimers() {
    rlEasaMayaThinkingTimers.forEach(function (id) {
      try { clearTimeout(id); } catch (e) { /* ignore */ }
    });
    rlEasaMayaThinkingTimers = [];
  }

  function rlEasaMayaRemoveThinkingRow() {
    rlEasaMayaClearThinkingTimers();
    var el = document.getElementById('rlEasaMayaThinkingRow');
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function rlEasaMayaShowThinkingRow(host) {
    if (!host) return;
    rlEasaMayaRemoveThinkingRow();
    var wrap = document.createElement('div');
    wrap.id = 'rlEasaMayaThinkingRow';
    wrap.className = 'rl-easa-maya-msg-row rl-easa-maya-msg-row--maya';
    wrap.appendChild(rlEasaMayaBuildAvatarEl(true, 'is-thinking'));
    var thinkStack = document.createElement('div');
    thinkStack.className = 'rl-easa-maya-msg-stack';
    thinkStack.innerHTML = '<div class="rl-easa-maya-bubble-wrap"><div class="rl-easa-chat-bubble rl-easa-chat-bubble-system">'
      + '<div class="rl-easa-chat-meta">Maya</div>'
      + '<p class="rl-easa-maya-thinking-text" id="rlEasaMayaThinkingText">Maya is thinking…</p></div></div>';
    wrap.appendChild(thinkStack);
    host.appendChild(wrap);
    try { host.scrollTop = host.scrollHeight; } catch (e) { /* ignore */ }
    var txt = document.getElementById('rlEasaMayaThinkingText');
    var t1 = setTimeout(function () {
      if (txt && document.getElementById('rlEasaMayaThinkingRow')) {
        txt.textContent = 'Maya is looking this up in the regulations…';
      }
    }, 2400);
    var t2 = setTimeout(function () {
      if (txt && document.getElementById('rlEasaMayaThinkingRow')) {
        txt.textContent = 'Maya is still checking the official material…';
      }
    }, 8000);
    rlEasaMayaThinkingTimers.push(t1, t2);
  }

  function rlEasaMayaSanitizeAssistantMarkdown(md) {
    return String(md || '')
      .replace(/^\s*(?:batch_id|node_uid|ERulesId|ERULESID)\s*[:=]\s*\S+\s*$/gim, '')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function rlEasaMayaRenderChips(host, refs) {
    if (!host || !Array.isArray(refs) || !refs.length) return;
    refs.slice(0, 12).forEach(function (r) {
      var bid = parseInt(String(r.batch_id), 10) || 0;
      var nuid = String(r.node_uid || '').trim();
      if (!bid || !nuid) return;
      var rawTitle = rlEasaMayaCleanTitle(String(r.title || ''));
      var corpus = rlEasaMayaBatchName(bid);
      var title = rawTitle || corpus || 'Regulation reference';
      var matched = Array.isArray(r.matched_terms) ? r.matched_terms : [];
      var hl = (matched[0] || '').trim();
      var row = document.createElement('div');
      row.className = 'rl-easa-maya-chips';
      var lab = document.createElement('span');
      lab.className = 'rl-easa-maya-chip-label';
      lab.textContent = corpus && rawTitle ? (corpus + ' · ' + rawTitle) : title;
      var act = document.createElement('span');
      act.className = 'rl-easa-maya-chip-actions';
      var b1 = document.createElement('button');
      b1.type = 'button';
      b1.className = 'btn btn-sm';
      b1.textContent = 'Open excerpt';
      b1.addEventListener('click', function () {
        rlEasaAiOpenExcerptModal(bid, nuid, matched.length ? matched : (hl ? [hl] : []), rawTitle || corpus || 'Regulation excerpt');
      });
      var b2 = document.createElement('button');
      b2.type = 'button';
      b2.className = 'btn btn-sm';
      b2.textContent = 'Open in tree';
      b2.addEventListener('click', function () {
        var treeSec = document.getElementById('rlEasaTreeSection');
        if (treeSec && treeSec.scrollIntoView) treeSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var prom = rlEasaRevealTreeNode(bid, nuid, hl);
        if (prom && typeof prom.catch === 'function') {
          prom.catch(function (err) {
            try {
              var msg = (err && err.message) ? String(err.message) : 'Could not reveal that section in the tree.';
              alert('Maya cannot reveal this section right now.\n\n' + msg);
            } catch (e) { /* ignore */ }
          });
        }
      });
      act.appendChild(b1);
      act.appendChild(b2);
      row.appendChild(lab);
      row.appendChild(act);
      host.appendChild(row);
    });
  }

  function rlEasaAiCloseExcerptModal() {
    var m = document.getElementById('rlEasaAiExcerptModal');
    if (m) m.hidden = true;
  }

  /**
   * If the AI references a wrapper/TOC, walk down via tree_children and pick the first substantive descendant.
   * Read-only — does not modify any tree state. Returns a Promise of { batchId, nodeUid } to actually display.
   */
  function rlEasaMayaResolveSubstantiveTarget(batchId, nodeUid, depth) {
    var bid = parseInt(String(batchId), 10) || 0;
    var nuid = String(nodeUid || '').trim();
    if (!bid || !nuid || depth >= 4) {
      return Promise.resolve({ batchId: bid, nodeUid: nuid });
    }
    return rlEasaFetchNodeDetail(bid, nuid).then(function (j) {
      if (!j.ok || !j.node) return { batchId: bid, nodeUid: nuid, _detail: j };
      var n = j.node;
      var nt = String(n.node_type || '').toLowerCase();
      var isWrapper = nt === 'document' || nt === 'frontmatter' || nt === 'toc' || nt === 'backmatter';
      var hasStructured = Array.isArray(n.structured_blocks) && n.structured_blocks.length > 0;
      if (!isWrapper) return { batchId: bid, nodeUid: nuid, _detail: j };
      if (hasStructured && depth >= 1) return { batchId: bid, nodeUid: nuid, _detail: j };
      return fetch(api + '?action=tree_children&batch_id=' + encodeURIComponent(String(bid))
          + '&parent_uid=' + encodeURIComponent(nuid), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (kids) {
          if (!kids || !kids.ok || !Array.isArray(kids.nodes) || !kids.nodes.length) {
            return { batchId: bid, nodeUid: nuid, _detail: j };
          }
          var pick = null;
          for (var i = 0; i < kids.nodes.length; i++) {
            var c = kids.nodes[i];
            var cnt = String(c.node_type || '').toLowerCase();
            if (cnt && cnt !== 'document' && cnt !== 'frontmatter' && cnt !== 'toc' && cnt !== 'backmatter') {
              pick = c;
              break;
            }
          }
          if (!pick) pick = kids.nodes[0];
          var childUid = String((pick && pick.node_uid) || '').trim();
          if (!childUid || childUid === nuid) return { batchId: bid, nodeUid: nuid, _detail: j };
          return rlEasaMayaResolveSubstantiveTarget(bid, childUid, depth + 1);
        })
        .catch(function () { return { batchId: bid, nodeUid: nuid, _detail: j }; });
    }).catch(function () { return { batchId: bid, nodeUid: nuid }; });
  }

  function rlEasaAiOpenExcerptModal(batchId, nodeUid, terms, titleHint) {
    var m = document.getElementById('rlEasaAiExcerptModal');
    var band = document.getElementById('rlEasaAiExcerptBand');
    var techPre = document.getElementById('rlEasaAiExcerptTechPre');
    var body = document.getElementById('rlEasaAiExcerptBody');
    var ttl = document.getElementById('rlEasaAiExcerptTitle');
    if (!m || !band || !body) return;
    rlEasaAiExcerptState = {
      batchId: parseInt(String(batchId), 10) || 0,
      nodeUid: String(nodeUid || '').trim(),
      terms: Array.isArray(terms) ? terms.slice() : []
    };
    if (ttl) ttl.textContent = titleHint ? String(titleHint) : 'Regulation excerpt';
    band.className = 'rl-easa-maya-excerpt-band rl-easa-inline-band rl-easa-band rl-easa-band-neu';
    band.innerHTML = 'Loading…<small></small>';
    if (techPre) techPre.textContent = '';
    body.innerHTML = '';
    body.className = 'rl-easa-ai-excerpt-body rl-easa-detail-body';
    m.hidden = false;

    function renderNode(n) {
      var b = n.rule_band || 'ir';
      if (['ir', 'amc', 'gm', 'neu'].indexOf(b) < 0) b = 'ir';
      var titleLine = n.title_display || n.title || n.source_erules_id || n.node_uid || '—';
      var crumb = (n.breadcrumb || '').trim();
      var leg = rlEasaBandLegend(b);
      band.className = 'rl-easa-maya-excerpt-band rl-easa-inline-band rl-easa-band rl-easa-band-' + b;
      band.innerHTML = esc(titleLine) + '<small>' + esc(leg) + '</small>';
      if (crumb) {
        var crumbSpan = document.createElement('div');
        crumbSpan.className = 'rl-easa-band-crumb';
        crumbSpan.style.cssText = 'margin-top:6px;font-size:12px;opacity:0.95;';
        crumbSpan.textContent = crumb;
        band.appendChild(crumbSpan);
      }
      var eid = (n.source_erules_id || '').trim();
      if (techPre) {
        techPre.textContent = [
          'batch_id=' + String(n.batch_id || ''),
          'node_uid=' + String(n.node_uid || ''),
          eid ? 'ERulesId=' + eid : ''
        ].filter(Boolean).join('\n');
      }
      var blk = Array.isArray(n.structured_blocks) && n.structured_blocks.length > 0
        ? rlEasaStructuredBlocksHtml(n.structured_blocks)
        : '';
      if (blk) {
        body.className = 'rl-easa-ai-excerpt-body rl-easa-detail-body rl-easa-detail-body-structured';
        body.innerHTML = blk;
      } else {
        body.className = 'rl-easa-ai-excerpt-body rl-easa-detail-body';
        var br = (n.body_reading || n.plain_text_display || n.plain_text || '').trim();
        body.textContent = br || '[No body text on this section]';
      }
      rlEasaAiHighlightTerms(body, rlEasaAiExcerptState.terms);
    }

    rlEasaMayaResolveSubstantiveTarget(rlEasaAiExcerptState.batchId, rlEasaAiExcerptState.nodeUid, 0)
      .then(function (resolved) {
        var rb = parseInt(String(resolved.batchId), 10) || rlEasaAiExcerptState.batchId;
        var ru = String(resolved.nodeUid || rlEasaAiExcerptState.nodeUid).trim();
        rlEasaAiExcerptState.batchId = rb;
        rlEasaAiExcerptState.nodeUid = ru;
        if (resolved._detail && resolved._detail.ok && resolved._detail.node
            && String(resolved._detail.node.node_uid || '') === ru) {
          renderNode(resolved._detail.node);
          return;
        }
        return rlEasaFetchNodeDetail(rb, ru).then(function (j) {
          if (!j.ok || !j.node) {
            band.className = 'rl-easa-maya-excerpt-band rl-easa-inline-band rl-easa-band rl-easa-band-neu';
            band.innerHTML = esc((j && j.error) ? j.error : 'Maya could not load this section.') + '<small></small>';
            body.className = 'rl-easa-ai-excerpt-body rl-easa-detail-body';
            body.textContent = 'The referenced section could not be opened. Try clicking "Open in tree" instead, or ask Maya to point to a more specific rule.';
            return;
          }
          renderNode(j.node);
        });
      })
      .catch(function (e) {
        band.className = 'rl-easa-maya-excerpt-band rl-easa-inline-band rl-easa-band rl-easa-band-neu';
        band.innerHTML = esc((e && e.message) || 'Error') + '<small></small>';
      });
  }

  (function bindAiExcerptModal() {
    var cl = document.getElementById('rlEasaAiExcerptClose');
    var m = document.getElementById('rlEasaAiExcerptModal');
    var treeBtn = document.getElementById('rlEasaAiExcerptOpenTree');
    if (cl) cl.addEventListener('click', rlEasaAiCloseExcerptModal);
    if (m) {
      m.addEventListener('click', function (e) {
        if (e.target === m) rlEasaAiCloseExcerptModal();
      });
    }
    if (treeBtn) {
      treeBtn.addEventListener('click', function () {
        var bid = rlEasaAiExcerptState.batchId;
        var uid = rlEasaAiExcerptState.nodeUid;
        var terms = rlEasaAiExcerptState.terms || [];
        var needle = (terms[0] || '').trim();
        rlEasaAiCloseExcerptModal();
        if (!bid || !uid) return;
        var treeSec = document.getElementById('rlEasaTreeSection');
        if (treeSec && treeSec.scrollIntoView) treeSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        rlEasaRevealTreeNode(bid, uid, needle).catch(function () {});
      });
    }
  })();

  /** Friendly corpus name from a batch row from /status (no raw .xml file names). */
  function rlEasaMayaFriendlyBatchName(b) {
    if (!b || typeof b !== 'object') return '';
    var raw = String(b.original_filename || '').toLowerCase();
    if (/aircrew/.test(raw) || /\bfcl\b/.test(raw) || /\bpart-fcl\b/.test(raw) || /flight\s*crew/.test(raw)) return 'EAR Flight Crew';
    if (/\bair[-\s]*ops\b/.test(raw) || /flight\s*operations/.test(raw) || /\bops\b/.test(raw)) return 'EAR Flight Operations';
    if (/\bpart[-\s]*is\b/.test(raw) || /information\s*security/.test(raw)) return 'EAR Part-IS';
    if (/cs[-\s]*fstd/.test(raw) || /fstd/.test(raw) || /simulator/.test(raw)) return 'EAR CS-FSTD';
    if (/\bmed\b/.test(raw) || /medical/.test(raw)) return 'EAR Aircrew Medical';
    if (/\bcat\b/.test(raw) || /commercial\s*air\s*transport/.test(raw)) return 'EAR Air Operations (CAT)';
    if (/\bnco\b/.test(raw) || /\bspo\b/.test(raw)) return 'EAR Air Operations';
    if (/balloon/.test(raw)) return 'EAR Balloons';
    if (/sailplane/.test(raw)) return 'EAR Sailplanes';
    return '';
  }

  /** Cached map of batch_id -> friendly name, populated from /status. */
  var rlEasaMayaBatchNames = {};
  function rlEasaMayaApplyBatchNamesFromStatus(j) {
    if (!j || !Array.isArray(j.batches)) return;
    j.batches.forEach(function (b) {
      var bid = parseInt(String(b.id || '0'), 10);
      if (!bid) return;
      rlEasaMayaBatchNames[bid] = rlEasaMayaFriendlyBatchName(b)
        || ('EAR batch ' + bid);
    });
  }
  function rlEasaMayaBatchName(bid) {
    var n = parseInt(String(bid), 10) || 0;
    if (!n) return '';
    return rlEasaMayaBatchNames[n] || '';
  }

  /** Strip raw .xml filenames and internal IDs from any titles surfaced by the model. */
  function rlEasaMayaCleanTitle(raw) {
    var t = String(raw == null ? '' : raw).trim();
    if (!t) return '';
    if (/\.xml$/i.test(t)) {
      t = t.replace(/^easy access rules for\s+/i, '');
      t = t.replace(/\.xml$/i, '');
      t = t.replace(/\s*-\s*part.*$/i, '');
    }
    t = t.replace(/\b(batch_id|node_uid|ERulesId)\s*[:=]\s*[A-Za-z0-9_\-]+/gi, '');
    return t.replace(/\s+/g, ' ').trim();
  }

  function rlEasaAiFillSessionSelect(_sessions, _currentId) { /* dropdown removed */ }

  function rlEasaMayaCreateUserRow(text, createdAt) {
    var ts = rlEasaFormatChatTime(createdAt == null ? '' : createdAt);
    var urow = document.createElement('div');
    urow.className = 'rl-easa-maya-msg-row rl-easa-maya-msg-row--user';
    urow.appendChild(rlEasaMayaBuildAvatarEl(false, ''));
    var ustack = document.createElement('div');
    ustack.className = 'rl-easa-maya-msg-stack';
    ustack.innerHTML = '<div class="rl-easa-maya-bubble-wrap">'
      + '<div class="rl-easa-chat-bubble rl-easa-chat-bubble-user">'
      + '<div class="rl-easa-chat-meta">You</div><p>' + esc(String(text || '')) + '</p></div></div>'
      + (ts ? '<span class="rl-easa-maya-msg-time">' + esc(ts) + '</span>' : '');
    urow.appendChild(ustack);
    return urow;
  }

  function rlEasaMayaCreateAssistantRow(content, responseJsonStr, createdAt) {
    var ts = rlEasaFormatChatTime(createdAt == null ? '' : createdAt);
    var innerBody = rlEasaFormatAiAnswerHtml(rlEasaMayaSanitizeAssistantMarkdown(String(content || ''))) || '<p>(empty)</p>';
    var mrow = document.createElement('div');
    mrow.className = 'rl-easa-maya-msg-row rl-easa-maya-msg-row--maya';
    mrow.appendChild(rlEasaMayaBuildAvatarEl(true, ''));
    var mstack = document.createElement('div');
    mstack.className = 'rl-easa-maya-msg-stack';
    mstack.innerHTML = '<div class="rl-easa-maya-bubble-wrap"><div class="rl-easa-chat-bubble rl-easa-chat-bubble-system">'
      + '<div class="rl-easa-chat-meta">Maya</div>'
      + '<div class="rl-easa-maya-msg-body">' + innerBody + '</div></div></div>'
      + (ts ? '<span class="rl-easa-maya-msg-time">' + esc(ts) + '</span>' : '');
    mrow.appendChild(mstack);
    var bubble = mrow.querySelector('.rl-easa-chat-bubble');
    if (bubble && responseJsonStr && typeof responseJsonStr === 'string') {
      try {
        var o = JSON.parse(responseJsonStr);
        var refs = (o && Array.isArray(o.primary_references)) ? o.primary_references : [];
        rlEasaMayaRenderChips(bubble, refs);
      } catch (e0) { /* ignore */ }
    }
    return mrow;
  }

  /**
   * Cursor-based history state (auto-restore latest, lazy-load older).
   * `loaded` = current oldest message id rendered (0 if none).
   */
  var rlEasaMayaHist = {
    sessionId: 0,
    oldestId: 0,
    hasMore: false,
    loading: false
  };

  function rlEasaMayaDisconnectChatIo() {
    if (rlEasaMayaChatIo) {
      try { rlEasaMayaChatIo.disconnect(); } catch (e) { /* ignore */ }
      rlEasaMayaChatIo = null;
    }
  }

  function rlEasaMayaUpdateLoadEarlierFallbackVisibility() {
    var fb = document.getElementById('rlEasaChatLoadEarlierFallback');
    if (!fb) return;
    var need = rlEasaMayaHist.hasMore && typeof IntersectionObserver === 'undefined';
    fb.hidden = !need;
  }

  function rlEasaMayaSetupChatLoadSentinel() {
    var host = document.getElementById('rlEasaChatHistory');
    var sen = document.getElementById('rlEasaChatScrollSentinel');
    rlEasaMayaDisconnectChatIo();
    if (!host || !sen) return;
    if (!rlEasaMayaHist.hasMore || !rlEasaMayaHist.sessionId) {
      sen.hidden = true;
      rlEasaMayaUpdateLoadEarlierFallbackVisibility();
      return;
    }
    if (typeof IntersectionObserver === 'undefined') {
      sen.hidden = true;
      rlEasaMayaUpdateLoadEarlierFallbackVisibility();
      return;
    }
    sen.hidden = false;
    rlEasaMayaUpdateLoadEarlierFallbackVisibility();
    rlEasaMayaChatIo = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting && en.target === sen) rlEasaMayaLoadOlder();
      });
    }, { root: host, rootMargin: '72px 0px 0px 0px', threshold: 0 });
    rlEasaMayaChatIo.observe(sen);
  }

  function rlEasaMayaResetThreadAndRender(messages, hasMore) {
    var host = document.getElementById('rlEasaChatHistory');
    if (!host) return;
    rlEasaMayaDisconnectChatIo();
    host.innerHTML = '';
    var sentinel = document.createElement('div');
    sentinel.className = 'rl-easa-maya-chat-sentinel';
    sentinel.id = 'rlEasaChatScrollSentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    sentinel.hidden = true;
    var fbWrap = document.createElement('div');
    fbWrap.id = 'rlEasaChatLoadEarlierFallback';
    fbWrap.className = 'rl-easa-maya-load-earlier-fallback';
    fbWrap.hidden = true;
    var fbBtn = document.createElement('button');
    fbBtn.type = 'button';
    fbBtn.className = 'btn btn-sm';
    fbBtn.id = 'rlEasaChatLoadEarlierFallbackBtn';
    fbBtn.textContent = 'Load earlier messages';
    fbBtn.addEventListener('click', rlEasaMayaLoadOlder);
    fbWrap.appendChild(fbBtn);
    host.appendChild(sentinel);
    host.appendChild(fbWrap);
    var firstId = 0;
    (messages || []).forEach(function (row) {
      var role = String(row.role || '');
      var node;
      var tsField = rlEasaMsgTimestampField(row);
      if (role === 'user') node = rlEasaMayaCreateUserRow(row.content, tsField);
      else if (role === 'assistant') node = rlEasaMayaCreateAssistantRow(row.content, row.response_json, tsField);
      if (node) {
        host.appendChild(node);
        var rid = parseInt(String(row.id || '0'), 10);
        if (rid && (firstId === 0 || rid < firstId)) firstId = rid;
      }
    });
    rlEasaMayaHist.oldestId = firstId;
    rlEasaMayaHist.hasMore = !!hasMore;
    sentinel.hidden = !hasMore;
    rlEasaMayaSetupChatLoadSentinel();
    rlEasaMayaUpdateLoadEarlierFallbackVisibility();
    try { host.scrollTop = host.scrollHeight; } catch (e1) { /* ignore */ }
  }

  function rlEasaMayaPrependOlder(messages, hasMore) {
    var host = document.getElementById('rlEasaChatHistory');
    var fb = document.getElementById('rlEasaChatLoadEarlierFallback');
    if (!host) return;
    var prevHeight = host.scrollHeight;
    var anchor = fb && fb.nextSibling ? fb.nextSibling : null;
    var firstId = rlEasaMayaHist.oldestId;
    (messages || []).forEach(function (row) {
      var role = String(row.role || '');
      var node;
      var tsField = rlEasaMsgTimestampField(row);
      if (role === 'user') node = rlEasaMayaCreateUserRow(row.content, tsField);
      else if (role === 'assistant') node = rlEasaMayaCreateAssistantRow(row.content, row.response_json, tsField);
      if (node) {
        if (anchor) host.insertBefore(node, anchor); else host.appendChild(node);
        var rid = parseInt(String(row.id || '0'), 10);
        if (rid && (firstId === 0 || rid < firstId)) firstId = rid;
      }
    });
    rlEasaMayaHist.oldestId = firstId;
    rlEasaMayaHist.hasMore = !!hasMore;
    var sen = document.getElementById('rlEasaChatScrollSentinel');
    if (sen) sen.hidden = !hasMore;
    rlEasaMayaSetupChatLoadSentinel();
    rlEasaMayaUpdateLoadEarlierFallbackVisibility();
    try {
      var newHeight = host.scrollHeight;
      host.scrollTop = Math.max(0, newHeight - prevHeight);
    } catch (e2) { /* ignore */ }
  }

  function rlEasaMayaLoadOlder() {
    if (rlEasaMayaHist.loading || !rlEasaMayaHist.hasMore || !rlEasaMayaHist.sessionId) return;
    if (rlEasaMayaHist.oldestId <= 0) return;
    rlEasaMayaHist.loading = true;
    var fbBtn = document.getElementById('rlEasaChatLoadEarlierFallbackBtn');
    if (fbBtn) fbBtn.disabled = true;
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'easa_ai_chat_bootstrap',
        session_id: rlEasaMayaHist.sessionId,
        before_id: rlEasaMayaHist.oldestId,
        limit: 15
      })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.j || !x.j.ok) return;
        rlEasaMayaPrependOlder(x.j.messages || [], !!x.j.has_more);
      })
      .catch(function () { /* ignore */ })
      .finally(function () {
        rlEasaMayaHist.loading = false;
        if (fbBtn) fbBtn.disabled = false;
      });
  }

  function rlEasaAiRenderHistory(messages, hasMore) {
    rlEasaMayaResetThreadAndRender(messages || [], !!hasMore);
  }

  function rlEasaAiLoadBootstrap() {
    var hint = document.getElementById('rlEasaChatPersistHint');
    var boot = { action: 'easa_ai_chat_bootstrap', limit: 5 };
    if (rlEasaAiSessionId > 0) boot.session_id = rlEasaAiSessionId;
    return fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(boot)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.j || !x.j.ok) return;
        if (!x.j.chat_supported) {
          if (hint) hint.textContent = x.j.chat_migrate_hint || '';
          return;
        }
        if (hint) hint.textContent = 'Maya remembers this chat after reload.';
        var cur = parseInt(String(x.j.current_session_id || '0'), 10) || 0;
        if (cur > 0) rlEasaAiSessionId = cur;
        rlEasaMayaHist.sessionId = rlEasaAiSessionId;
        rlEasaAiRenderHistory(x.j.messages || [], !!x.j.has_more);
      })
      .catch(function () {
        if (hint) hint.textContent = '';
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
      var hm;
      if ((hm = /^###\s+(.+)$/.exec(line))) {
        flushPara();
        flushList();
        html.push('<h3>' + inlineFmt(hm[1].trim()) + '</h3>');
        return;
      }
      if ((hm = /^##\s+(.+)$/.exec(line))) {
        flushPara();
        flushList();
        html.push('<h2>' + inlineFmt(hm[1].trim()) + '</h2>');
        return;
      }
      if ((hm = /^#\s+(.+)$/.exec(line))) {
        flushPara();
        flushList();
        html.push('<h2>' + inlineFmt(hm[1].trim()) + '</h2>');
        return;
      }
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

  function rlEasaTreeBatchShortLabel(b) {
    var raw = String(b.original_filename || '').toLowerCase();
    if (/aircrew|part-fcl|\bfcl\b|flight[-\s]*crew/.test(raw)) return 'Aircrew';
    if (/air[-\s]*ops|commercial air transport|flight operations|flight-ops|\bspo\b|\bnco\b|\bcat\b/.test(raw)) return 'Air OPS';
    if (/part-is|information security|partis/.test(raw)) return 'Part-IS';
    if (/cs[-\s]*fstd|fstd/.test(raw)) return 'CS-FSTD';
    if (/balloon/.test(raw)) return 'Balloons';
    if (/sailplane|gliding/.test(raw)) return 'Sailplanes';
    if (/medical|part-med|part-mc/.test(raw)) return 'Medical';
    return 'Regulation source';
  }

  function rlEasaTreeBatchSortTuple(b) {
    var cat = rlEasaTreeBatchShortLabel(b);
    var ORDER = { 'Aircrew': 0, 'Air OPS': 1, 'Medical': 2, 'CS-FSTD': 3, 'Part-IS': 4, 'Balloons': 5, 'Sailplanes': 6, 'Regulation source': 7 };
    var nodes = parseInt(String(b.staging_nodes || '0'), 10) || 0;
    var st = String(b.status || '');
    var liveFirst = (st === 'ready_for_review' && nodes > 0) ? 0 : 1;
    var ord = Object.prototype.hasOwnProperty.call(ORDER, cat) ? ORDER[cat] : 99;
    var id = parseInt(String(b.id || '0'), 10) || 0;
    return [liveFirst, ord, -id];
  }

  function rlEasaFillBatchSelects(j) {
    var sel = document.getElementById('rlEasaTreeBatch');
    if (!sel) return;
    var prev = sel.value;
    var batches = (j && j.batches) ? j.batches.slice() : [];
    batches.sort(function (a, b) {
      var ta = rlEasaTreeBatchSortTuple(a);
      var tb = rlEasaTreeBatchSortTuple(b);
      for (var i = 0; i < ta.length; i++) {
        if (ta[i] !== tb[i]) return ta[i] < tb[i] ? -1 : 1;
      }
      return 0;
    });
    var countBy = {};
    batches.forEach(function (b) {
      var L = rlEasaTreeBatchShortLabel(b);
      countBy[L] = (countBy[L] || 0) + 1;
    });
    sel.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = '';
    ph.textContent = 'Select your EASA Easy Access Rules';
    sel.appendChild(ph);
    for (var i = 0; i < batches.length; i++) {
      var b = batches[i];
      var bid = parseInt(String(b.id || '0'), 10) || 0;
      if (!bid) continue;
      var base = rlEasaTreeBatchShortLabel(b);
      var label = base;
      if (countBy[base] > 1) {
        var rawD = String(b.updated_at || b.created_at || '').trim();
        if (rawD) {
          try {
            var isoD = rawD.replace(' ', 'T');
            if (!(/[zZ]|[+-]\d{2}:?\d{2}$/.test(isoD))) isoD += 'Z';
            var dd = new Date(isoD);
            if (!isNaN(dd.getTime())) {
              label = base + ' · ' + dd.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            }
          } catch (eD) { /* ignore */ }
        }
      }
      var o = document.createElement('option');
      o.value = String(bid);
      o.textContent = label;
      sel.appendChild(o);
    }
    if (prev) {
      for (var ti = 0; ti < sel.options.length; ti++) {
        if (sel.options[ti].value === prev) {
          sel.selectedIndex = ti;
          break;
        }
      }
    }
  }

  function rlEasaTreeMountIsEmptyish() {
    var m = document.getElementById('rlEasaTreeMount');
    if (!m) return true;
    if (m.querySelector('ul.rl-easa-tree-list')) return false;
    var txt = (m.textContent || '').trim().toLowerCase();
    if (!txt) return true;
    if (txt.indexOf('loading data') >= 0) return true;
    if (txt.indexOf('loading roots') >= 0) return true;
    if (txt.indexOf('loading tree path') >= 0) return true;
    if (txt.indexOf('loading regulations') >= 0) return true;
    if (m.querySelector('.rl-easa-tree-loading-center')) return true;
    if (m.querySelector('.rl-easa-tree-empty')) return true;
    return false;
  }

  function rlEasaBatchIsLive(b) {
    var nodes = parseInt(String(b && b.staging_nodes != null ? b.staging_nodes : '0'), 10) || 0;
    return String(b && b.status != null ? b.status : '') === 'ready_for_review' && nodes > 0;
  }

  var RL_EASA_LIVE_CAT_PRIORITY = ['Aircrew', 'Air OPS', 'Medical', 'CS-FSTD', 'Part-IS', 'Balloons', 'Sailplanes', 'Regulation source'];

  function rlEasaPickDefaultLiveBatchId(batches) {
    var live = (batches || []).filter(rlEasaBatchIsLive);
    if (!live.length) return 0;
    for (var p = 0; p < RL_EASA_LIVE_CAT_PRIORITY.length; p++) {
      var want = RL_EASA_LIVE_CAT_PRIORITY[p];
      for (var i = 0; i < live.length; i++) {
        if (rlEasaTreeBatchShortLabel(live[i]) === want) {
          return parseInt(String(live[i].id || '0'), 10) || 0;
        }
      }
    }
    return 0;
  }

  function rlEasaApplyDefaultTreeSelectionAfterStatus(j) {
    var treeSel = document.getElementById('rlEasaTreeBatch');
    var mount = document.getElementById('rlEasaTreeMount');
    var th = document.getElementById('rlEasaTreeHint');
    if (!treeSel) return;
    var defaultBid = rlEasaPickDefaultLiveBatchId(j && j.batches ? j.batches : []);
    if (!defaultBid) {
      rlEasaTreeBatchSilent = true;
      try {
        treeSel.selectedIndex = 0;
      } finally {
        setTimeout(function () { rlEasaTreeBatchSilent = false; }, 0);
      }
      if (mount && rlEasaTreeMountIsEmptyish()) {
        mount.innerHTML = '<div class="rl-easa-tree-empty rl-easa-tree-loading-center" role="status">'
          + '<span>No live regulation set is ready yet. Open <strong>Easy Access Download</strong>, finish parsing and indexing, then refresh this page.</span></div>';
      }
      if (th) {
        th.textContent = 'No live regulation set is ready yet. When at least one source is parsed and ready for review with indexed rules, it will load here automatically.';
      }
      return;
    }
    rlEasaTreeBatchSilent = true;
    try {
      for (var oi = 0; oi < treeSel.options.length; oi++) {
        if (treeSel.options[oi].value === String(defaultBid)) {
          treeSel.selectedIndex = oi;
          break;
        }
      }
    } finally {
      setTimeout(function () { rlEasaTreeBatchSilent = false; }, 0);
    }
    if (th) th.textContent = 'Loading regulation tree…';
    if (mount && rlEasaTreeMountIsEmptyish()) {
      rlEasaExecuteLoadTreeRoots(defaultBid);
    }
  }

  function rlEasaGetBatchRow(batchId) {
    var bid = parseInt(String(batchId || '0'), 10) || 0;
    return bid ? (rlEasaStatusBatchesById[bid] || null) : null;
  }

  function rlEasaTreeFindFirstLiMatchingTitle(ul, re) {
    if (!ul || !re) return null;
    var lis = ul.querySelectorAll(':scope > li');
    for (var i = 0; i < lis.length; i++) {
      var li = lis[i];
      var tit = li.querySelector(':scope > .rl-easa-tree-row .rl-easa-tree-section-title, :scope > .rl-easa-tree-row .rl-easa-tree-rule-title');
      var t = tit ? String(tit.textContent || '').trim() : '';
      if (t && re.test(t)) return li;
    }
    return null;
  }

  /** Uses rlEasaEnsureChildUlLoaded only — no changes to tree data or filtering. */
  function rlEasaTreeApplyDefaultOpenState(batchId, mount) {
    if (!rlEasaTreeDefaultOpenConfig.enabled || !mount) return Promise.resolve();
    var b = rlEasaGetBatchRow(batchId);
    if (!b || rlEasaTreeBatchShortLabel(b) !== rlEasaTreeDefaultOpenConfig.corpusShortLabel) {
      return Promise.resolve();
    }
    var pats = rlEasaTreeDefaultOpenConfig.expandPathTitleRegex;
    if (!pats || !pats.length) return Promise.resolve();
    var ul0 = mount.querySelector(':scope > ul.rl-easa-tree-list');
    if (!ul0) return Promise.resolve();

    function step(pi, parentUl) {
      if (pi >= pats.length) return Promise.resolve();
      var li = rlEasaTreeFindFirstLiMatchingTitle(parentUl, pats[pi]);
      if (!li) return Promise.resolve();
      return rlEasaEnsureChildUlLoaded(li, batchId, null).then(function () {
        var nextUl = li.querySelector(':scope > ul.rl-easa-tree-list');
        if (!nextUl) return;
        return step(pi + 1, nextUl);
      });
    }
    return step(0, ul0).catch(function () { /* optional default path missing in corpus */ });
  }

  function rlEasaExecuteLoadTreeRoots(bid) {
    var mount = document.getElementById('rlEasaTreeMount');
    var hint = document.getElementById('rlEasaTreeHint');
    if (!mount) return;
    var b = parseInt(String(bid), 10) || 0;
    if (!b) {
      mount.innerHTML = '<p class="rl-easa-tree-loading-msg" style="margin:0;color:#64748b;">Select a regulation set above to load the tree.</p>';
      if (hint) hint.textContent = 'Choose an Easy Access regulation from the list.';
      return;
    }
    mount.innerHTML = RL_EASA_TREE_LOADING_HTML;
    if (hint) hint.textContent = 'Loading regulation tree…';
    rlEasaTreeFetchTreeChildrenJson(b, '')
      .then(function (j) {
        return rlEasaTreeResolveLegalRootNodes(b, j);
      })
      .then(function (resolved) {
        rlEasaRenderTreeIntoMount(mount, b, resolved.nodes);
        if (hint) hint.textContent = '';
        return rlEasaTreeApplyDefaultOpenState(b, mount);
      })
      .catch(function (e) {
        mount.innerHTML = '<p class="rl-easa-tree-loading-msg" style="margin:0;color:#991b1b;">'
          + esc(e.message || 'Could not load the regulation tree.') + '</p>';
        if (hint) hint.textContent = 'Something went wrong while loading the tree.';
      });
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
        + 'Indexed rules: ' + esc(String(nodes))
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
      var short = rlEasaTreeBatchShortLabel(payload);
      var disp = rlEasaSourceDisplayName(payload);
      var regTitle = short !== 'Regulation source' ? short : disp;
      titleEl.textContent = regTitle;
      var nodes = payload.staging_nodes != null ? (parseInt(String(payload.staging_nodes), 10) || 0) : 0;
      var st = String(payload.status || '');
      var live = st === 'ready_for_review' && nodes > 0;
      var pub = rlEasaPublicationHint(payload);
      var statusLine = st === 'failed' ? 'Import failed — see Technical overview for details.'
        : st === 'staging' ? 'Parsing or indexing in progress.'
        : st === 'uploaded' ? 'Uploaded — parse has not finished yet.'
        : st === 'ready_for_review' ? (live ? 'Ready — this regulation set can be browsed in the tree.' : 'Marked ready but no indexed rules yet.')
        : esc(st || 'Unknown');
      ident.innerHTML = '<p style="margin:0 0 10px;"><strong>Regulation set</strong> ' + esc(regTitle)
        + (disp && disp !== regTitle ? '<br><span class="rl-drop-meta">' + esc(disp) + '</span>' : '')
        + '</p>'
        + '<p style="margin:0 0 10px;"><strong>Indexed rules</strong> ' + esc(String(nodes)) + '</p>'
        + '<p style="margin:0 0 10px;"><strong>Live source</strong> '
        + (live ? 'Yes — this set is ready to open in Live Easy Access Rules.' : 'Not yet — finish parsing or wait until rules are indexed.')
        + '</p>'
        + '<p style="margin:0 0 10px;"><strong>Status</strong> ' + statusLine + '</p>'
        + (pub ? '<p style="margin:0 0 10px;"><strong>Publication</strong> ' + esc(pub) + '</p>' : '')
        + '<p class="rl-drop-meta" style="margin:10px 0 0;">Internal id, original export file name, row counts, and checksum appear under <strong>Technical overview</strong> below.</p>';
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
            var mbCap = rlEasaMaxUploadBytes / (1024 * 1024);
            limEl.textContent = 'Max ' + (Math.round(mbCap * 10) / 10) + 'MB';
          }
        } else if (limEl) {
          limEl.textContent = '';
        }
        rlEasaFillBatchSelects(x.j);
        rlEasaMayaApplyBatchNamesFromStatus(x.j);
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
        rlEasaStatusBatchesById = {};
        batches.forEach(function (bb) {
          var bid0 = parseInt(String(bb && bb.id != null ? bb.id : '0'), 10) || 0;
          if (bid0) rlEasaStatusBatchesById[bid0] = bb;
        });
        rlEasaRebuildSourceRow(x.j);
        rlEasaApplyDefaultTreeSelectionAfterStatus(x.j);
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
        rlEasaAiLoadBootstrap();
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

    function revealProgressFull(msg) {
      mount.innerHTML = '<div class="rl-easa-tree-loading-center rl-easa-tree-reveal-full" role="status">'
        + '<span class="rl-easa-tree-reveal-msg" aria-live="polite">' + esc(msg) + '</span></div>';
    }
    function revealProgressBar(msg) {
      var bar = mount.querySelector(':scope > .rl-easa-tree-reveal-status');
      if (!bar) {
        bar = document.createElement('div');
        bar.className = 'rl-easa-tree-reveal-status';
        bar.setAttribute('role', 'status');
        mount.insertBefore(bar, mount.firstChild);
      }
      bar.innerHTML = '<span class="rl-easa-tree-reveal-msg" aria-live="polite">' + esc(msg) + '</span>';
    }
    function revealDescendLabel(idx) {
      if (idx === 0) return 'Opening Part-FCL…';
      if (idx === 1) return 'Opening Annex I…';
      return 'Opening nested section…';
    }
    function revealProgressClear() {
      var bar = mount.querySelector(':scope > .rl-easa-tree-reveal-status');
      if (bar) bar.remove();
    }

    revealProgressFull('Opening regulation tree…');

    return rlEasaAncestorUidChain(batchId, targetUid).then(function (chain) {
      /* chain: root ... target */
      if (!chain.length) throw new Error('Empty ancestor chain');
      revealProgressFull('Finding parent sections…');
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
            var head0 = path[0];
            if (ul.querySelector(':scope > li[data-node-uid="' + rlEasaCssEscape(head0) + '"]')) break;
            path.shift();
          }
          if (!path.length) throw new Error('Tree roots do not contain this ancestor chain.');
          function descend(idx) {
            if (idx >= path.length) return Promise.resolve(null);
            var uid = path[idx];
            var li = ul.querySelector(':scope > li[data-node-uid="' + rlEasaCssEscape(uid) + '"]');
            if (!li) return Promise.reject(new Error('Could not find node ' + uid + ' in tree (try Load tree roots).'));
            if (idx === path.length - 1) {
              revealProgressBar('Opening selected section…');
              return Promise.resolve(li);
            }
            revealProgressBar(revealDescendLabel(idx));
            return rlEasaEnsureChildUlLoaded(li, batchId, revealOpts).then(function () {
              var nextUl = li.querySelector(':scope > ul.rl-easa-tree-list');
              if (!nextUl) return Promise.reject(new Error('No children container'));
              ul = nextUl;
              return descend(idx + 1);
            });
          }
          return descend(0).then(function (li) {
            revealProgressClear();
            if (!li) return;
            try {
              li.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (e0) {}
            rlEasaShowNodeDetail(batchId, targetUid, li, true);
          });
        });
    }).catch(function (e) {
      mount.innerHTML = '<p class="rl-easa-tree-loading-msg" style="margin:0;color:#991b1b;">' + esc(e.message || 'Tree navigation failed') + '</p>';
      throw e;
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

  var rlEasaTreeSel = document.getElementById('rlEasaTreeBatch');
  if (rlEasaTreeSel) {
    rlEasaTreeSel.addEventListener('change', function () {
      if (rlEasaTreeBatchSilent) return;
      var bid = parseInt(String(rlEasaTreeSel.value || '0'), 10) || 0;
      rlEasaExecuteLoadTreeRoots(bid);
    });
  }

  var chatSendBtn = document.getElementById('rlEasaChatSendBtn') || document.getElementById('rlEasaChatAskBtn');
  var chatHistEl = document.getElementById('rlEasaChatHistory');
  if (chatSendBtn && chatHistEl) {
    var qInput = document.getElementById('rlEasaChatQ');
    if (qInput) {
      qInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          if (!chatSendBtn.disabled) chatSendBtn.click();
        }
      });
    }
    chatSendBtn.addEventListener('click', function () {
      var qEl = document.getElementById('rlEasaChatQ');
      var q = qEl ? (qEl.value || '').trim() : '';
      if (!q) return;
      var payload = {
        action: 'regulatory_compare_ai',
        query: q,
        use_ai: true,
        include_ecfr: false,
        ecfr_title_number: 14,
        ecfr_section: ''
      };
      if (rlEasaAiSessionId > 0) payload.session_id = rlEasaAiSessionId;
      chatHistEl.appendChild(rlEasaMayaCreateUserRow(q, Date.now()));
      if (qEl) qEl.value = '';
      rlEasaMayaShowThinkingRow(chatHistEl);
      chatSendBtn.disabled = true;
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          rlEasaMayaRemoveThinkingRow();
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Request failed');
          if (x.j.session_id) {
            rlEasaAiSessionId = parseInt(String(x.j.session_id), 10) || rlEasaAiSessionId;
          }
          var pl = x.j;
          var hist = document.getElementById('rlEasaChatHistory');
          if (pl.chat_supported && hist) {
            rlEasaMayaHist.sessionId = rlEasaAiSessionId;
            var aiBlock2 = pl.ai_error ? 'Maya got stuck. Please try again.' : (pl.answer_markdown || pl.ai_answer || '');
            var refs2 = Array.isArray(pl.primary_references) ? pl.primary_references : [];
            if (!refs2.length && Array.isArray(pl.easa_sources)) {
              pl.easa_sources.slice(0, 8).forEach(function (s) {
                refs2.push({
                  title: (s.title || '').trim() || 'Regulation',
                  batch_id: s.batch_id,
                  node_uid: s.node_uid,
                  erules_id: s.source_erules_id || '',
                  matched_terms: [],
                  quote: ''
                });
              });
            }
            var pj2 = JSON.stringify({
              ok: true,
              answer_markdown: aiBlock2,
              primary_references: refs2,
              secondary_references: Array.isArray(pl.secondary_references) ? pl.secondary_references : [],
              confidence: pl.confidence || 'medium'
            });
            hist.appendChild(rlEasaMayaCreateAssistantRow(aiBlock2, pj2, Date.now()));
            try { hist.scrollTop = hist.scrollHeight; } catch (e0) { /* ignore */ }
            return;
          }
          return rlEasaAiLoadBootstrap().then(function () {
            if (!hist) return;
            var aiBlock = pl.ai_error ? 'Maya got stuck. Please try again.' : (pl.answer_markdown || pl.ai_answer || '');
            var refs = Array.isArray(pl.primary_references) ? pl.primary_references : [];
            if (!refs.length && Array.isArray(pl.easa_sources)) {
              pl.easa_sources.slice(0, 8).forEach(function (s) {
                refs.push({
                  title: (s.title || '').trim() || 'Regulation',
                  batch_id: s.batch_id,
                  node_uid: s.node_uid,
                  erules_id: s.source_erules_id || '',
                  matched_terms: [],
                  quote: ''
                });
              });
            }
            var pj = JSON.stringify({
              ok: true,
              answer_markdown: aiBlock,
              primary_references: refs,
              secondary_references: Array.isArray(pl.secondary_references) ? pl.secondary_references : [],
              confidence: pl.confidence || 'medium'
            });
            hist.appendChild(rlEasaMayaCreateAssistantRow(aiBlock, pj, Date.now()));
            try { hist.scrollTop = hist.scrollHeight; } catch (e1) { /* ignore */ }
          });
        })
        .catch(function (e) {
          rlEasaMayaRemoveThinkingRow();
          var mrow = document.createElement('div');
          mrow.className = 'rl-easa-maya-msg-row rl-easa-maya-msg-row--maya';
          mrow.appendChild(rlEasaMayaBuildAvatarEl(true, ''));
          var estack = document.createElement('div');
          estack.className = 'rl-easa-maya-msg-stack';
          estack.innerHTML = '<div class="rl-easa-maya-bubble-wrap"><div class="rl-easa-chat-bubble rl-easa-chat-bubble-system">'
            + '<div class="rl-easa-chat-meta">Maya</div><p>' + esc('Maya got stuck. Please try again.') + '</p>'
            + '<p class="rl-drop-meta" style="margin:8px 0 0;">' + esc(e.message || 'Error') + '</p></div></div>'
            + '<span class="rl-easa-maya-msg-time">' + esc(rlEasaFormatChatTime(Date.now())) + '</span>';
          mrow.appendChild(estack);
          chatHistEl.appendChild(mrow);
          try { chatHistEl.scrollTop = chatHistEl.scrollHeight; } catch (e3) { /* ignore */ }
        })
        .finally(function () { chatSendBtn.disabled = false; });
    });
  }

  loadStatus();
})();
</script>
