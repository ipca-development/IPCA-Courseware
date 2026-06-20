<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/layout.php';
require_once __DIR__ . '/../../../../src/flight_training/AdminLogbookService.php';

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new AdminLogbookService($pdo);
$error = '';
$logbookId = (int)($_GET['logbook_id'] ?? 0);
if ($logbookId <= 0 && (int)($_GET['student_user_id'] ?? 0) > 0) {
    try {
        $logbookId = $service->getOrCreateLogbook((int)$_GET['student_user_id'], null, $actorUserId);
        redirect('/admin/flight_training/logbooks/view.php?logbook_id=' . $logbookId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$schemaReady = $service->schemaReady();
$workspace = array();
if ($schemaReady && $logbookId > 0) {
    try {
        $workspace = $service->loadWorkspace($logbookId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$workspaceJson = json_encode($workspace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

cw_header('Flight Training · Admin Logbook Workspace');
?>

<style>
.alogw{display:flex;flex-direction:column;gap:16px}
.alogw-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px;border-radius:22px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.16)}
.alogw-title{margin:0;font-size:25px}.alogw-sub{margin:7px 0 0;color:#dbeafe;font-size:13px}.alogw-kicker{margin:0 0 5px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.alogw-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.alogw-btn--primary{background:#1d4f89;color:#fff}.alogw-btn--secondary{background:#eef2ff;color:#1e3a8a}.alogw-btn--ghost{background:#fff;color:#334155;border:1px solid rgba(15,23,42,.12)}.alogw-btn--danger{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.alogw-status{font-size:12px;font-weight:800;color:#64748b}.alogw-error{padding:13px 16px;border-radius:16px;font-size:13px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.alogw-panel{border:1px solid rgba(15,23,42,.08);border-radius:20px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.alogw-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(15,23,42,.07)}
.alogw-panel-title{margin:0;font-size:15px;color:#102845}.alogw-panel-text{margin:3px 0 0;color:#64748b;font-size:12px}
.alogw-cards{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:10px}.alogw-card{padding:13px 14px;border-radius:17px;background:#fff;border:1px solid rgba(15,23,42,.08);box-shadow:0 8px 20px rgba(15,23,42,.05)}.alogw-card-label{font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748b}.alogw-card-value{margin-top:4px;font-size:20px;font-weight:900;color:#102845}
.alogw-egle-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px 16px}.alogw-egle-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.alogw-egle-field{display:flex;flex-direction:column;gap:5px}.alogw-egle-field--wide{grid-column:1/-1}.alogw-egle-label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.alogw-egle-input{border:1px solid rgba(15,23,42,.13);border-radius:12px;padding:8px 10px;font:inherit;font-size:12px;color:#102845;background:#fff}.alogw-egle-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.alogw-egle-status{font-size:12px;font-weight:900;color:#475569;background:#e2e8f0;border-radius:999px;padding:7px 10px}.alogw-egle-candidates{display:flex;flex-direction:column;gap:7px;max-height:210px;overflow:auto}.alogw-egle-candidate{display:flex;justify-content:space-between;gap:8px;align-items:center;border:1px solid rgba(15,23,42,.08);border-radius:13px;padding:8px;background:#f8fafc}.alogw-egle-candidate strong{font-size:12px;color:#102845}.alogw-egle-candidate span{font-size:11px;color:#64748b}
.alogw-import{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(15,23,42,.07);background:#f8fafc}.alogw-import-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.alogw-input,.alogw-select{border:1px solid rgba(15,23,42,.13);border-radius:12px;padding:8px 10px;font:inherit;font-size:12px;background:#fff;color:#102845}.alogw-extract-status{margin-left:auto;font-size:12px;font-weight:900;color:#475569;background:#e2e8f0;border-radius:999px;padding:7px 10px}
.alogw-grid-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(15,23,42,.07);background:#fff}
.alogw-table-wrap{overflow-x:hidden;overflow-y:auto;max-height:720px;background:#fff}.alogw-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:0;background:#fff}.alogw-table th{position:sticky;top:0;z-index:3;padding:6px 6px 6px 3px;background:#f8fafc;border-right:1px solid #e2e8f0;border-bottom:1px solid #cbd5e1;font-size:8px;line-height:1;white-space:nowrap;text-transform:uppercase;letter-spacing:0;color:#475569;text-align:center;overflow:hidden;text-overflow:ellipsis}.alogw-table th .alogw-col-resizer{position:absolute;top:0;right:-3px;width:7px;height:100%;cursor:col-resize;user-select:none;touch-action:none}.alogw-table th .alogw-col-resizer:hover{background:rgba(37,99,235,.18)}.alogw-table td{padding:5px 3px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;vertical-align:middle;background:#fff;font-size:9px;line-height:1.1;color:#102845;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center}.alogw-table td.alogw-num{font-variant-numeric:tabular-nums}.alogw-table tr:nth-child(even) td{background:#f8fafc}.alogw-table tfoot th,.alogw-table tfoot td{position:sticky;z-index:2;box-sizing:border-box;height:24px;min-height:24px;max-height:24px;padding:5px 3px;border-right:1px solid #cbd5e1;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:8px;line-height:14px;background:#eef2ff;color:#1e3a8a}.alogw-table tfoot th{bottom:24px;border-top:1px solid #cbd5e1;border-bottom:1px solid #cbd5e1;text-transform:uppercase;font-weight:900}.alogw-table tfoot td{bottom:0;border-bottom:0;font-size:9px;font-weight:900;font-variant-numeric:tabular-nums}.alogw-table input[type=checkbox]{width:auto;height:auto}.alogw-row-actions{display:flex;gap:4px;justify-content:center}.alogw-mini-btn{border:0;border-radius:999px;padding:3px 6px;font-size:8px;font-weight:900;cursor:pointer}.alogw-mini-btn--edit{background:#eef2ff;color:#1e3a8a}.alogw-mini-btn--delete{background:#fff1f2;color:#be123c}.alogw-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 6px;font-size:8px;font-weight:900;text-transform:uppercase;background:#e2e8f0;color:#475569}.alogw-status-pill--accepted,.alogw-status-pill--ok{background:#dcfce7;color:#166534}.alogw-status-pill--imported,.alogw-status-pill--needs_review,.alogw-status-pill--flagged{background:#fef3c7;color:#92400e}.alogw-status-pill--rejected{background:#fee2e2;color:#991b1b}
.alogw-modal{position:fixed;inset:0;z-index:9999;display:none;background:rgba(15,23,42,.62);padding:18px}.alogw-modal.is-open{display:flex;align-items:center;justify-content:center}.alogw-modal-card{width:min(1100px,96vw);height:min(760px,92vh);border-radius:22px;background:#fff;box-shadow:0 28px 80px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden}.alogw-modal-head{flex:0 0 auto;display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid rgba(15,23,42,.08)}.alogw-modal-body{flex:1;background:#0f172a;overflow:auto;display:flex;align-items:center;justify-content:center}.alogw-modal-body img{max-width:100%;transform-origin:center;transition:transform .15s ease}.alogw-image-empty{color:#cbd5e1;text-align:center;padding:28px;font-size:13px}
.alogw-edit-body{flex:1 1 auto;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:10px 14px;overflow:auto;background:#fff}.alogw-edit-field{display:flex;flex-direction:column;gap:3px}.alogw-edit-field--wide{grid-column:1/-1}.alogw-edit-label{font-size:9px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;color:#64748b}.alogw-edit-input,.alogw-edit-textarea,.alogw-edit-select{border:1px solid rgba(15,23,42,.13);border-radius:9px;padding:6px 8px;font:inherit;font-size:12px;color:#102845;background:#fff}.alogw-edit-textarea{min-height:52px;resize:vertical}.alogw-edit-actions{flex:0 0 auto;display:flex;gap:8px;justify-content:flex-end;padding:10px 14px;border-top:1px solid rgba(15,23,42,.08);background:#f8fafc}
.alogw-modal-card form{min-height:0;display:flex;flex-direction:column;flex:1 1 auto}
.alogw-8710-wrap{overflow:auto;background:#fff}.alogw-8710{width:100%;min-width:1080px;border-collapse:separate;border-spacing:0;table-layout:fixed}.alogw-8710 th,.alogw-8710 td{border-right:1px solid #cbd5e1;border-bottom:1px solid #cbd5e1;padding:7px 5px;text-align:center;font-size:10px;line-height:1.1;color:#102845;background:#fff}.alogw-8710 th{background:#f8fafc;font-size:9px;font-weight:900;text-transform:uppercase;color:#475569}.alogw-8710 .alogw-8710-row-head{text-align:left;font-weight:900;background:#f8fafc}.alogw-8710 .alogw-8710-num{font-variant-numeric:tabular-nums;font-weight:800}.alogw-8710-note{padding:8px 12px;font-size:11px;color:#64748b;background:#f8fafc;border-top:1px solid rgba(15,23,42,.06)}
.alogw-bottom{display:grid;grid-template-columns:1fr;gap:16px}.alogw-req-list{max-height:460px;overflow:auto}.alogw-req-row{display:grid;grid-template-columns:74px minmax(170px,1fr) 76px 76px 118px 102px;gap:8px;padding:10px 14px;border-bottom:1px solid rgba(15,23,42,.06);align-items:center}.alogw-req-head{position:sticky;top:0;z-index:1;background:#f8fafc;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#64748b}.alogw-req-cell{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.alogw-req-value{text-align:center;font-variant-numeric:tabular-nums}.alogw-req-actions{display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap}.alogw-req-evidence{grid-column:1/-1;margin-top:8px;padding:9px 10px;border-radius:12px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);font-size:11px;color:#334155}.alogw-req-evidence-title{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-weight:900;color:#102845}.alogw-req-evidence-list{margin:7px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:5px}.alogw-req-evidence-list li{display:flex;gap:7px;align-items:flex-start;flex-wrap:wrap}.alogw-req-evidence-empty{color:#92400e;background:#fffbeb;border-color:#fde68a}.alogw-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:900;text-transform:uppercase;white-space:nowrap}.alogw-badge--pass{background:#dcfce7;color:#166534}.alogw-badge--fail{background:#fee2e2;color:#991b1b}
@media(max-width:1200px){.alogw-egle-grid,.alogw-bottom{grid-template-columns:1fr}.alogw-egle-form{grid-template-columns:1fr 1fr}.alogw-cards{grid-template-columns:repeat(2,minmax(120px,1fr))}.alogw-extract-status{margin-left:0}}
</style>

<div class="alogw" id="alogWorkspace" data-logbook-id="<?= (int)$logbookId ?>">
  <header class="alogw-top">
    <div>
      <p class="alogw-kicker">Admin · Flight Training · Admin Logbook</p>
      <h1 class="alogw-title"><?= h((string)($workspace['logbook']['student_name'] ?? 'Logbook Workspace')) ?></h1>
      <p class="alogw-sub"><?= h((string)($workspace['logbook']['student_email'] ?? 'Structured flight data, requirements, and future form variables.')) ?></p>
    </div>
    <a class="alogw-btn alogw-btn--secondary" href="/admin/flight_training/logbooks/index.php">Back to Logbooks</a>
  </header>

  <?php if ($error !== ''): ?><div class="alogw-error"><?= h($error) ?></div><?php endif; ?>

  <section class="alogw-cards" id="alogTotalsCards"></section>

  <section class="alogw-panel">
    <div class="alogw-panel-head">
      <div>
        <h2 class="alogw-panel-title">E-GLE Connection</h2>
        <p class="alogw-panel-text">Temporary read-only connection for direct E-GLE sync. Passwords are not displayed back to the browser.</p>
        <p class="alogw-panel-text" id="alogEgleSyncSummary">Last sync: none · Pending reviews: 0</p>
      </div>
      <span class="alogw-egle-status" id="alogEgleStatus">E-GLE: disconnected</span>
    </div>
    <div class="alogw-egle-grid">
      <div>
        <p class="alogw-panel-text">Configure host, database, username, password, SSL, and notes in the settings modal.</p>
        <div class="alogw-egle-actions">
          <button class="alogw-btn alogw-btn--primary" type="button" id="alogOpenEgleSettings">E-GLE Settings</button>
          <button class="alogw-btn alogw-btn--ghost" type="button" id="alogDisconnectEgle">Disconnect E-GLE</button>
          <button class="alogw-btn alogw-btn--secondary" type="button" id="alogSyncStudent">Sync Student</button>
          <button class="alogw-btn alogw-btn--ghost" type="button" id="alogSyncAll">Sync All Students</button>
          <button class="alogw-btn alogw-btn--ghost" type="button" id="alogReviewChanges">Review Changes</button>
        </div>
      </div>
      <div>
        <div class="alogw-egle-actions" style="margin-top:0">
          <input class="alogw-egle-input" id="alogEgleSearchQuery" placeholder="Search E-GLE by email or name">
          <button class="alogw-btn alogw-btn--secondary" type="button" id="alogSearchEgleUsers">Search / Suggest Mapping</button>
          <button class="alogw-btn alogw-btn--danger" type="button" id="alogDeleteMapping">Unmap</button>
        </div>
        <div class="alogw-egle-candidates" id="alogEgleCandidates"></div>
      </div>
    </div>
  </section>

  <section class="alogw-panel">
    <div class="alogw-panel-head">
      <div>
        <h2 class="alogw-panel-title">Electronic Logbook</h2>
        <p class="alogw-panel-text">Upload source scans, attempt extraction, review candidate rows, then save accepted trusted entries.</p>
      </div>
      <span class="alogw-status" id="alogStatus">Ready</span>
    </div>
    <div class="alogw-import">
      <form class="alogw-import-form" id="alogCsvForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_csv">
        <input type="hidden" name="logbook_id" value="<?= (int)$logbookId ?>">
        <input class="alogw-input" type="file" name="csv" accept=".csv,text/csv">
        <button class="alogw-btn alogw-btn--primary" type="submit">Import CSV</button>
      </form>
      <form class="alogw-import-form" id="alogUploadForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_page">
        <input type="hidden" name="logbook_id" value="<?= (int)$logbookId ?>">
        <input class="alogw-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
        <button class="alogw-btn alogw-btn--secondary" type="submit">Upload Images</button>
      </form>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogExtractPage">Import / Extract</button>
      <select class="alogw-select" id="alogPageSelect"></select>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogViewImage">View Source Pages</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogShowRequirements">Requirement Verification</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogShowIacra">IACRA Summary</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogShow8710">FAA 8710 Summary</button>
      <a class="alogw-btn alogw-btn--secondary" target="_blank" href="/admin/flight_training/logbooks/print.php?format=easa&amp;logbook_id=<?= (int)$logbookId ?>">Print EASA Logbook</a>
      <a class="alogw-btn alogw-btn--secondary" target="_blank" href="/admin/flight_training/logbooks/print.php?format=faa&amp;logbook_id=<?= (int)$logbookId ?>">Print FAA Logbook</a>
      <span class="alogw-extract-status" id="alogExtractionStatus">Extraction status: no page</span>
    </div>
    <div class="alogw-grid-tools">
      <button class="alogw-btn alogw-btn--primary" type="button" id="alogAddRow">Add Row</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogSelectAllRows">Select All</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogSelectReviewRows">Select Imported / Review</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogClearSelection">Clear Selection</button>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogOpenBulkEdit">Bulk Edit Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogAcceptRows">Accept Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogRejectRows">Reject Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogSplitRow">Split Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogMergeRows">Merge Selected</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogFlagRows">Flag Selected</button>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogDeleteRows">Delete Selected</button>
      <select class="alogw-select" id="alogTimeZoneSelect" title="Time display">
        <option value="UTC">UTC</option>
        <option value="Europe/Brussels">Belgium Local</option>
        <option value="America/Los_Angeles">California Local</option>
        <option value="America/New_York">Eastern US Local</option>
        <option value="America/Phoenix">Arizona Local</option>
        <option value="Europe/London">United Kingdom Local</option>
        <option value="browser">Browser Local</option>
      </select>
      <select class="alogw-select" id="alogRequirementSelect" title="Choose the required event that the selected logbook row(s) prove"></select>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogAssignRequirement">Tag Selected as Required Event</button>
      <button class="alogw-btn alogw-btn--ghost" type="button" id="alogUnassignRequirement">Untag Selected from Event</button>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogClearRequirementTags">Remove All Tags</button>
    </div>
    <div class="alogw-table-wrap">
      <table class="alogw-table">
        <thead id="alogTableHead"></thead>
        <tbody id="alogTableBody"></tbody>
      </table>
    </div>
  </section>

  <section class="alogw-panel">
    <div class="alogw-panel-head">
      <div>
        <h2 class="alogw-panel-title">FAA 8710 Record of Pilot Time</h2>
        <p class="alogw-panel-text">Section III-style summary generated from accepted/trusted Admin Logbook entries.</p>
      </div>
    </div>
    <div class="alogw-8710-wrap" id="alog8710Summary"></div>
  </section>

  <section class="alogw-bottom">
    <div class="alogw-panel">
      <div class="alogw-panel-head">
        <div>
          <h2 class="alogw-panel-title">Requirement Verification</h2>
          <p class="alogw-panel-text">PASS/FAIL preview based on totals and explicit assignments.</p>
        </div>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogAddRequirementCategory">Add Requirement Category</button>
      </div>
      <div class="alogw-req-list" id="alogRequirements"></div>
    </div>
  </section>
</div>

<div class="alogw-modal" id="alogImageModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title">Source Logbook Image</h2>
        <p class="alogw-panel-text">Use as source evidence while reviewing extracted or edited rows.</p>
      </div>
      <div>
        <button class="alogw-btn alogw-btn--ghost" type="button" id="alogZoomOut">-</button>
        <button class="alogw-btn alogw-btn--ghost" type="button" id="alogZoomIn">+</button>
        <button class="alogw-btn alogw-btn--danger" type="button" id="alogCloseImage">Close</button>
      </div>
    </div>
    <div class="alogw-modal-body" id="alogImageBox"></div>
  </div>
</div>

<div class="alogw-modal" id="alogEditModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title">Edit Logbook Row</h2>
        <p class="alogw-panel-text">Detailed edits happen here so the main logbook remains readable.</p>
      </div>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogCancelEdit">Cancel</button>
    </div>
    <form id="alogEditForm">
      <div class="alogw-edit-body" id="alogEditFields"></div>
      <div class="alogw-edit-actions">
        <button class="alogw-btn alogw-btn--primary" type="submit">Save Row</button>
      </div>
    </form>
  </div>
</div>

<div class="alogw-modal" id="alogEgleSettingsModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title">E-GLE Connection Settings</h2>
        <p class="alogw-panel-text">Temporary read-only credentials. Password is masked and never displayed back to the browser.</p>
      </div>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogCloseEgleSettings">Cancel</button>
    </div>
    <form id="alogEgleConnectionForm">
      <div class="alogw-edit-body">
        <label class="alogw-edit-field"><span class="alogw-edit-label">Host</span><input class="alogw-edit-input" name="host" autocomplete="off" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Port</span><input class="alogw-edit-input" name="port" type="number" value="3306" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Database Name</span><input class="alogw-edit-input" name="database" autocomplete="off" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Username</span><input class="alogw-edit-input" name="username" autocomplete="off" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Password</span><input class="alogw-edit-input" name="password" type="password" autocomplete="new-password"></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">SSL</span><select class="alogw-edit-select" name="ssl"><option value="0">No</option><option value="1">Yes</option></select></label>
        <label class="alogw-edit-field alogw-edit-field--wide"><span class="alogw-edit-label">Connection Notes</span><input class="alogw-edit-input" name="notes" autocomplete="off"></label>
      </div>
      <div class="alogw-edit-actions">
        <button class="alogw-btn alogw-btn--primary" type="submit">Save & Test Connection</button>
      </div>
    </form>
  </div>
</div>

<div class="alogw-modal" id="alogBulkEditModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title">Bulk Edit Selected Flights</h2>
        <p class="alogw-panel-text">Set selected flights quickly. Checked time flags use each row's total time; unchecked flags set that field to zero. Leave blank to keep unchanged.</p>
      </div>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogCancelBulkEdit">Cancel</button>
    </div>
    <form id="alogBulkEditForm">
      <div class="alogw-edit-body">
        <label class="alogw-edit-field"><span class="alogw-edit-label">SE</span><select class="alogw-edit-select" name="single_engine_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">ME</span><select class="alogw-edit-select" name="multi_engine_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Dual</span><select class="alogw-edit-select" name="dual_received_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">PIC</span><select class="alogw-edit-select" name="pic_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Solo</span><select class="alogw-edit-select" name="solo_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Cross Country</span><select class="alogw-edit-select" name="cross_country_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Day Time</span><select class="alogw-edit-select" name="day_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Night Time</span><select class="alogw-edit-select" name="night_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Instrument</span><select class="alogw-edit-select" name="instrument_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Basic Instrument</span><select class="alogw-edit-select" name="basic_instrument_flying_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">FNPT / Sim</span><select class="alogw-edit-select" name="fnpt_simulator_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Day Landings</span><input class="alogw-edit-input" name="day_landings" type="number" min="0" placeholder="Keep"></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Night Landings</span><input class="alogw-edit-input" name="night_landings" type="number" min="0" placeholder="Keep"></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Status</span><select class="alogw-edit-select" name="review_status"><option value="">Keep</option><option value="imported">Imported</option><option value="needs_review">Needs review</option><option value="accepted">Accepted</option><option value="rejected">Rejected</option><option value="flagged">Flagged</option></select></label>
      </div>
      <div class="alogw-edit-actions">
        <button class="alogw-btn alogw-btn--primary" type="submit">Apply to Selected</button>
      </div>
    </form>
  </div>
</div>

<div class="alogw-modal" id="alogRequirementCategoryModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title" id="alogRequirementCategoryTitle">Requirement Category</h2>
        <p class="alogw-panel-text">Edit the rule that appears in requirement verification and the “Tag Selected as Required Event” dropdown.</p>
      </div>
      <button class="alogw-btn alogw-btn--danger" type="button" id="alogCancelRequirementCategory">Cancel</button>
    </div>
    <form id="alogRequirementCategoryForm">
      <input type="hidden" name="id">
      <div class="alogw-edit-body">
        <label class="alogw-edit-field"><span class="alogw-edit-label">Authority</span><input class="alogw-edit-input" name="authority" value="FAA_PART_61" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Certificate</span><input class="alogw-edit-input" name="certificate" value="PPL" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Requirement Key</span><input class="alogw-edit-input" name="requirement_key" placeholder="faa61.ppl.first_solo" required></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Status</span><select class="alogw-edit-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select></label>
        <label class="alogw-edit-field alogw-edit-field--wide"><span class="alogw-edit-label">Label</span><input class="alogw-edit-input" name="label" required></label>
        <label class="alogw-edit-field alogw-edit-field--wide"><span class="alogw-edit-label">Description</span><textarea class="alogw-edit-textarea" name="description"></textarea></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Minimum Time</span><input class="alogw-edit-input" type="number" step="0.01" name="minimum_time"></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Minimum Distance NM</span><input class="alogw-edit-input" type="number" step="0.1" name="minimum_distance_nm"></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Minimum Count / Landings</span><input class="alogw-edit-input" type="number" step="1" min="0" name="minimum_count"></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">One Flight Multiple Requirements</span><select class="alogw-edit-select" name="allow_one_flight_multiple_requirements"><option value="1">Allowed</option><option value="0">Not allowed</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Multiple Flights One Requirement</span><select class="alogw-edit-select" name="allow_multiple_flights_one_requirement"><option value="1">Allowed</option><option value="0">Not allowed</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Evaluation Method</span><select class="alogw-edit-select" name="evaluation_method" id="alogRequirementEvaluationMethod"><option value="manual_assignment">Tagged entry count</option><option value="selected_entries_sum">Sum field from tagged entries</option><option value="selected_entries_distance">Sum tagged cross-country distance</option><option value="total_metric">Total logbook metric</option><option value="filtered_sum">Filtered logbook sum</option><option value="credited_total_time">Credited total time with simulator cap</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Metric / Tagged Entry Field</span><select class="alogw-edit-select" name="selected_entry_metric" id="alogRequirementSelectedMetric"><option value="">Not applicable</option><option value="total_flight_time">Total flight time</option><option value="dual_received_time">Dual received time</option><option value="solo_time">Solo time</option><option value="pic_time">PIC time</option><option value="cross_country_time">Cross-country time</option><option value="solo_cross_country_time">Solo cross-country time</option><option value="dual_cross_country_time">Dual cross-country time</option><option value="night_time">Night time</option><option value="instrument_time">Instrument time</option><option value="basic_instrument_flying_time">Basic instrument flying</option><option value="night_landings">Night landings</option><option value="day_landings">Day landings</option><option value="towered_airport_landings">Towered airport landings</option><option value="cross_country_distance_nm">Cross-country distance NM</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Total Logbook Metric</span><select class="alogw-edit-select" name="total_metric" id="alogRequirementTotalMetric"><option value="">Not applicable</option><option value="total_flight_time">Total flight time</option><option value="dual_received_time">Dual received time</option><option value="solo_time">Solo time</option><option value="pic_time">PIC time</option><option value="cross_country_time">Cross-country time</option><option value="dual_cross_country_time">Dual cross-country time</option><option value="solo_cross_country_time">Solo cross-country time</option><option value="pic_cross_country_time">PIC cross-country time</option><option value="night_time">Night time</option><option value="instrument_time">Instrument time</option><option value="basic_instrument_flying_time">Basic instrument flying</option><option value="night_landings">Night landings</option><option value="day_landings">Day landings</option><option value="towered_airport_landings">Towered airport landings</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Filter Preset</span><select class="alogw-edit-select" name="filter_preset" id="alogRequirementFilterPreset"><option value="">No filter</option><option value="dual">Dual flights only</option><option value="solo">Solo flights only</option><option value="cross_country">Cross-country flights only</option><option value="night">Night flights only</option><option value="dual_cross_country">Dual cross-country flights</option><option value="solo_cross_country">Solo cross-country flights</option><option value="dual_night">Dual night flights</option><option value="solo_towered">Solo towered takeoff/landing flights</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Simulator Credit Cap</span><input class="alogw-edit-input" type="number" step="0.1" min="0" name="sim_credit_cap" value="2.5"></label>
        <input type="hidden" name="automatic_rules_json" value="{}">
        <input type="hidden" name="manual_rules_json" value='{"evidence":"selected_logbook_entries"}'>
      </div>
      <div class="alogw-edit-actions">
        <button class="alogw-btn alogw-btn--primary" type="submit">Save Category</button>
      </div>
    </form>
  </div>
</div>

<script>
window.IPCA_ADMIN_LOGBOOK = <?= $workspaceJson ?: '{}' ?>;
(function(){
  const api = '/admin/api/admin_logbook_api.php';
  const egleApi = '/admin/api/egle_import_api.php';
  const root = document.getElementById('alogWorkspace');
  const logbookId = Number(root.dataset.logbookId || 0);
  const statusEl = document.getElementById('alogStatus');
  let data = window.IPCA_ADMIN_LOGBOOK || {};
  let entries = (data.entries || []).map(row => ({...row, _dirty:false}));
  let zoom = 1;
  const columnWidthStorageKey = `ipca.adminLogbook.${logbookId}.columnWidths.v1`;
  const timeZoneStorageKey = `ipca.adminLogbook.timeZone.v1`;
  const defaultColumnWidths = {select:24,actions:52,entry_date:64,departure_airport:52,departure_time:40,arrival_airport:52,arrival_time:40,aircraft_registration:58,aircraft_type:48,single_engine_time:34,multi_engine_time:34,pic_time:36,copilot_time:42,dual_received_time:38,solo_time:38,cross_country_time:34,cross_country_distance_nm:44,day_time:38,night_time:42,instrument_time:42,basic_instrument_flying_time:48,fnpt_simulator_time:44,day_landings:38,night_landings:38,instructor_name:74,total_flight_time:44,remarks:78,review_status:58};
  let columnWidths = loadColumnWidths();
  let selectedTimeZone = loadTimeZone();
  const cols = [
    ['select',''],['actions','Actions'],['entry_date','Date'],['departure_airport','DEP AD'],['departure_time','DEP T'],['arrival_airport','ARR AD'],['arrival_time','ARR T'],
    ['aircraft_registration','AC REG'],['aircraft_type','AC TYP'],['single_engine_time','SE'],['multi_engine_time','ME'],
    ['pic_time','PIC'],['copilot_time','Co-Pilot'],['dual_received_time','Dual'],['solo_time','Solo'],['cross_country_time','XC'],
    ['cross_country_distance_nm','XC NM'],['day_time','DAY'],['night_time','NIGHT'],['instrument_time','INSTR'],['basic_instrument_flying_time','B INSTR'],
    ['fnpt_simulator_time','FSTD'],['day_landings','LD D'],['night_landings','LD N'],['instructor_name','Instructor'],
    ['total_flight_time','Total'],['remarks','Remarks'],['review_status','Status']
  ];
  const editFields = [
    ['entry_date','Date','date'],['departure_airport','Departure','text'],['departure_time','Departure Time','time'],['arrival_airport','Arrival','text'],['arrival_time','Arrival Time','time'],
    ['aircraft_registration','Aircraft Registration / Device','text'],['aircraft_type','Aircraft Type','text'],['single_engine_time','SE Time','number'],['multi_engine_time','ME Time','number'],
    ['pic_time','PIC','number'],['copilot_time','Co-Pilot','number'],['dual_received_time','Dual','number'],['solo_time','Solo','number'],['cross_country_time','Cross Country','number'],
    ['cross_country_distance_nm','Cross Country NM','number'],['night_time','Night','number'],['instrument_time','Instrument','number'],['actual_instrument_time','Actual Instrument','number'],
    ['simulated_instrument_time','Simulated Instrument','number'],['basic_instrument_flying_time','Basic Instrument Flying','number'],['fnpt_simulator_time','FNPT / Simulator','number'],
    ['day_time','Day Time','number'],['day_landings','Day Landings','number'],['night_landings','Night Landings','number'],['towered_airport_landings','Towered Landings','number'],['instructor_name','Instructor','text'],
    ['total_flight_time','Total Time','number'],['remarks','Remarks','textarea'],['endorsements','Endorsements','textarea'],['review_status','Status','status']
  ];
  const numeric = new Set(['single_engine_time','multi_engine_time','pic_time','copilot_time','dual_received_time','solo_time','cross_country_time','cross_country_distance_nm','day_time','night_time','instrument_time','actual_instrument_time','simulated_instrument_time','basic_instrument_flying_time','fnpt_simulator_time','day_landings','night_landings','towered_airport_landings','total_flight_time']);
  const textareas = new Set(['remarks','endorsements']);
  function setStatus(msg){ statusEl.textContent = msg; }
  async function post(payload){
    const res = await fetch(api, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const json = await res.json();
    if(!json.ok){ throw new Error(json.error || 'Request failed'); }
    if(json.data){ data = json.data; entries = (data.entries || []).map(row => ({...row, _dirty:false})); renderAll(); }
    return json;
  }
  async function eglePost(payload){
    const res = await fetch(egleApi, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const json = await res.json();
    if(!json.ok){ throw new Error(json.error || 'E-GLE request failed'); }
    return json;
  }
  async function refreshWorkspace(){
    const res = await fetch(api + '?action=workspace&logbook_id=' + encodeURIComponent(logbookId));
    const json = await res.json();
    if(!json.ok){ throw new Error(json.error || 'Workspace refresh failed'); }
    data = json.data; entries = (data.entries || []).map(row => ({...row, _dirty:false})); renderAll();
  }
  function esc(value){ return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function decodeJsonObject(value){
    if(value && typeof value === 'object') return value;
    try {
      const parsed = JSON.parse(String(value || '{}'));
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch(err) {
      return {};
    }
  }
  function categoryById(id){
    const categoryId = Number(id || 0);
    return (data.requirement_categories || []).find(c => Number(c.id || 0) === categoryId) || null;
  }
  function inferredSelectedEntryMetric(requirementKey){
    const key = String(requirementKey || '').toLowerCase();
    if(key.includes('night_takeoffs_landings')) return 'night_landings';
    if(key.includes('towered_airport_takeoffs_landings') || key.includes('towered_airport_landings')) return 'towered_airport_landings';
    return '';
  }
  function renderAll(){ renderCards(); renderPages(); renderTable(); render8710Summary(); renderRequirements(); renderVariables(); }
  function loadColumnWidths(){
    try {
      const saved = JSON.parse(localStorage.getItem(columnWidthStorageKey) || '{}');
      return saved && typeof saved === 'object' ? saved : {};
    } catch(err) {
      return {};
    }
  }
  function saveColumnWidths(){
    try {
      localStorage.setItem(columnWidthStorageKey, JSON.stringify(columnWidths));
    } catch(err) {}
  }
  function loadTimeZone(){
    try {
      return localStorage.getItem(timeZoneStorageKey) || 'UTC';
    } catch(err) {
      return 'UTC';
    }
  }
  function saveTimeZone(value){
    selectedTimeZone = value || 'UTC';
    try {
      localStorage.setItem(timeZoneStorageKey, selectedTimeZone);
    } catch(err) {}
  }
  function columnWidth(key){
    return Math.max(24, Number(columnWidths[key] || defaultColumnWidths[key] || 48));
  }
  function applyColumnWidth(key){
    const width = columnWidth(key) + 'px';
    document.querySelectorAll(`[data-col="${key}"]`).forEach(el => { el.style.width = width; });
  }
  async function loadEgleStatus(){
    try {
      const studentId = data.logbook ? data.logbook.student_user_id : '';
      const res = await fetch(egleApi + '?action=status&logbook_id=' + encodeURIComponent(logbookId) + '&ipca_user_id=' + encodeURIComponent(studentId));
      const json = await res.json();
      if(!json.ok) throw new Error(json.error || 'E-GLE status failed');
      const connection = json.connection || {};
      document.getElementById('alogEgleStatus').textContent = connection.connected ? `E-GLE: connected to ${connection.database || 'database'}` : 'E-GLE: disconnected';
      const latest = json.latest_run || null;
      const last = latest ? `${latest.status} at ${latest.completed_at || latest.started_at}` : 'none';
      document.getElementById('alogEgleSyncSummary').textContent = `Last sync: ${last} · Pending reviews: ${json.pending_review_count || 0}`;
    } catch(err) {
      document.getElementById('alogEgleStatus').textContent = 'E-GLE: status unavailable';
    }
  }
  function renderCards(){
    const t = data.totals || {};
    const cards = [['Total',t.total_flight_time],['PIC',t.pic_time],['Dual',t.dual_received_time],['Solo',t.solo_time],['XC',t.cross_country_time],['Basic Instr',t.basic_instrument_flying_time],['FNPT / Sim',t.fnpt_simulator_time],['Night',t.night_time],['Instr',t.instrument_time],['Day Ldg',t.day_landings],['Night Ldg',t.night_landings],['SE',t.single_engine_time],['ME',t.multi_engine_time]];
    document.getElementById('alogTotalsCards').innerHTML = cards.map(([label,value]) => `<div class="alogw-card"><div class="alogw-card-label">${esc(label)}</div><div class="alogw-card-value">${esc(value ?? 0)}</div></div>`).join('');
  }
  function renderPages(){
    const pages = data.pages || [];
    const select = document.getElementById('alogPageSelect');
    const previous = select.value;
    select.innerHTML = pages.length ? pages.map(p => `<option value="${esc(p.id)}"${String(p.id) === previous ? ' selected' : ''}>Page ${esc(p.page_number)}</option>`).join('') : '<option value="">No source pages</option>';
    renderExtractionStatus();
    renderImage();
  }
  function currentPage(){
    const id = document.getElementById('alogPageSelect').value;
    return (data.pages || []).find(p => String(p.id) === String(id)) || null;
  }
  function renderExtractionStatus(){
    const page = currentPage();
    const label = page ? `Extraction status: ${page.extraction_status || 'manual'}` : 'Extraction status: no page';
    document.getElementById('alogExtractionStatus').textContent = label;
  }
  function renderImage(){
    const page = currentPage();
    const url = page ? page.image_url : '';
    const box = document.getElementById('alogImageBox');
    box.innerHTML = url ? `<img src="${esc(url)}" style="transform:scale(${zoom})">` : '<div class="alogw-image-empty">No uploaded source image yet.<br>Upload scans, run import/extract, then review candidate rows in the logbook table.</div>';
  }
  function renderTable(){
    const table = document.querySelector('.alogw-table');
    const colgroup = '<colgroup>' + cols.map(([key]) => `<col data-col="${esc(key)}" style="width:${columnWidth(key)}px">`).join('') + '</colgroup>';
    const existingColgroup = table.querySelector('colgroup');
    if(existingColgroup) {
      existingColgroup.outerHTML = colgroup;
    } else {
      table.insertAdjacentHTML('afterbegin', colgroup);
    }
    document.getElementById('alogTableHead').innerHTML = '<tr>' + cols.map(([key,label]) => `<th data-col="${esc(key)}" style="width:${columnWidth(key)}px" title="${esc(label)}"><span>${esc(label)}</span><span class="alogw-col-resizer" data-resize-col="${esc(key)}" aria-hidden="true"></span></th>`).join('') + '</tr>';
    document.getElementById('alogTableBody').innerHTML = entries.map((row,i) => '<tr data-i="'+i+'">' + cols.map(([key]) => cell(row,i,key)).join('') + '</tr>').join('');
    const existingFoot = table.querySelector('tfoot');
    const footHtml = renderTableFooter();
    if(existingFoot) {
      existingFoot.outerHTML = footHtml;
    } else {
      table.insertAdjacentHTML('beforeend', footHtml);
    }
  }
  function renderTableFooter(){
    const labelRow = '<tr>' + cols.map(([key,label]) => `<th data-col="${esc(key)}" style="width:${columnWidth(key)}px">${esc(label)}</th>`).join('') + '</tr>';
    const totals = tableTotals();
    const totalRow = '<tr>' + cols.map(([key]) => `<td data-col="${esc(key)}" style="width:${columnWidth(key)}px">${esc(totalCellValue(key, totals))}</td>`).join('') + '</tr>';
    return '<tfoot>' + labelRow + totalRow + '</tfoot>';
  }
  function cell(row,i,key){
    if(key === 'select') return `<td><input type="checkbox" data-select="${i}"></td>`;
    if(key === 'actions') return `<td><div class="alogw-row-actions"><button class="alogw-mini-btn alogw-mini-btn--edit" type="button" data-edit="${i}">Edit</button><button class="alogw-mini-btn alogw-mini-btn--delete" type="button" data-delete="${i}">Delete</button></div></td>`;
    if(key === 'cross_country_distance_nm') {
      const value = derivedCrossCountryDistance(row);
      return `<td class="alogw-num" title="${esc(displayValue(value))}">${esc(displayValue(value))}</td>`;
    }
    if(key === 'departure_time' || key === 'arrival_time') {
      const rawTime = key === 'arrival_time' ? arrivalTimeValue(row) : row[key];
      const value = displayLogbookTime(rawTime, row.entry_date);
      return `<td title="${esc(value)}">${esc(value)}</td>`;
    }
    if(key === 'review_status') {
      const status = String(row[key] || 'ok');
      return `<td><span class="alogw-status-pill alogw-status-pill--${esc(status)}">${esc(status.replace('_',' '))}</span></td>`;
    }
    const value = displayValue(row[key]);
    const cls = numeric.has(key) ? ' class="alogw-num"' : '';
    return `<td${cls} title="${esc(value)}">${esc(value)}</td>`;
  }
  function displayValue(value){
    if(value === null || value === undefined || value === '') return '';
    if(!Number.isNaN(Number(value)) && Number(value) === 0) return '';
    return String(value);
  }
  function totalCellValue(key, totals){
    if(key === 'entry_date') return 'Totals';
    if(key === 'select' || key === 'actions' || key === 'review_status') return '';
    if(!numeric.has(key)) return '';
    return displayValue((totals[key] || 0).toFixed(countFields().has(key) ? 0 : 2));
  }
  function tableTotals(){
    const totals = {};
    numeric.forEach(key => { totals[key] = 0; });
    entries.forEach(row => {
      numeric.forEach(key => {
        const raw = key === 'cross_country_distance_nm' ? derivedCrossCountryDistance(row) : row[key];
        const value = Number(raw || 0);
        if(!Number.isNaN(value)) totals[key] += value;
      });
    });
    return totals;
  }
  function countFields(){
    return new Set(['day_landings','night_landings','towered_airport_landings']);
  }
  function derivedCrossCountryDistance(row){
    if(Number(row.cross_country_time || 0) <= 0) return '';
    return row.cross_country_distance_nm || '';
  }
  function arrivalTimeValue(row){
    if(String(row.arrival_time || '').trim() !== '') return row.arrival_time;
    return deriveArrivalTime(row.departure_time, row.total_flight_time);
  }
  function deriveArrivalTime(departureTime, durationHours){
    const raw = String(departureTime || '').trim();
    const match = raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    const duration = Number(durationHours || 0);
    if(!match || duration <= 0) return '';
    const start = Number(match[1]) * 60 + Number(match[2]);
    const total = (start + Math.round(duration * 60)) % (24 * 60);
    const hh = String(Math.floor(total / 60)).padStart(2, '0');
    const mm = String(total % 60).padStart(2, '0');
    return `${hh}:${mm}`;
  }
  function displayLogbookTime(value, dateValue){
    const raw = String(value || '').trim();
    const match = raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    if(!match) return '';
    const hh = match[1].padStart(2, '0');
    const mm = match[2];
    const tz = selectedTimeZone === 'browser' ? Intl.DateTimeFormat().resolvedOptions().timeZone : selectedTimeZone;
    if(!tz || tz === 'UTC') return `${hh}:${mm}`;
    try {
      const date = String(dateValue || '1970-01-01').slice(0, 10);
      const instant = new Date(`${date}T${hh}:${mm}:00Z`);
      return new Intl.DateTimeFormat('en-GB', {hour:'2-digit', minute:'2-digit', hour12:false, timeZone:tz}).format(instant);
    } catch(err) {
      return `${hh}:${mm}`;
    }
  }
  function renderRequirements(){
    const reqs = data.requirements || [];
    const header = '<div class="alogw-req-row alogw-req-head"><span>Authority</span><span>Requirement</span><span>Required</span><span>Actual</span><span>Result</span><span>Actions</span></div>';
    const rows = reqs.map(r => {
      const categoryId = Number(r.requirement_category_id || 0);
      const unit = requirementUnit(r);
      const required = r.minimum === null || r.minimum === undefined ? null : Number(r.minimum);
      const actual = Number(r.value || 0);
      const short = required === null ? 0 : Math.max(0, required - actual);
      const status = String(r.status || 'fail').toUpperCase();
      const result = status === 'PASS' ? `PASS` : `${formatRequirementValue(short, unit)} short · FAIL`;
      const evidence = Array.isArray(r.evidence) ? r.evidence : [];
      const evidenceEntries = evidence.flatMap(ev => Array.isArray(ev.entries) ? ev.entries : []);
      const requiresTagging = r.requires_tagging !== false;
      const breakdownHtml = requirementBreakdownHtml(r);
      const evidenceHtml = evidenceEntries.length
        ? `<div class="alogw-req-evidence"><div class="alogw-req-evidence-title"><span>${esc(r.evidence_label || 'Tagged logbook record(s)')}</span><span class="alogw-badge alogw-badge--${status === 'PASS' ? 'pass':'fail'}">${esc(status === 'PASS' ? 'Satisfies requirement' : 'Does not satisfy yet')}</span></div><ul class="alogw-req-evidence-list">${evidenceEntries.map(entry => {
            const bits = [entry.entry_date, entry.route, entry.total_flight_time ? `${entry.total_flight_time}h` : '', entry.night_landings ? `${entry.night_landings} night LDG` : '', entry.day_landings ? `${entry.day_landings} day LDG` : '', entry.towered_airport_landings ? `${entry.towered_airport_landings} towered LDG` : '', entry.cross_country_distance_nm ? `${entry.cross_country_distance_nm} NM` : '', entry.aircraft_registration].filter(Boolean);
            const detail = bits.length ? bits.join(' · ') : `Entry #${entry.id || ''}`;
            return `<li><strong>${esc(detail)}</strong>${entry.remarks ? `<span>${esc(entry.remarks)}</span>` : ''}</li>`;
          }).join('')}</ul>${breakdownHtml}</div>`
        : (requiresTagging
          ? `<div class="alogw-req-evidence alogw-req-evidence-empty"><strong>No tagged logbook record yet.</strong> Select row(s), choose this event, then click “Tag Selected as Required Event”.</div>`
          : `<div class="alogw-req-evidence"><div class="alogw-req-evidence-title"><span>${esc(r.evidence_label || 'Calculated from accepted logbook rows')}</span><span class="alogw-badge alogw-badge--${status === 'PASS' ? 'pass':'fail'}">${esc(status === 'PASS' ? 'Satisfies requirement' : 'Does not satisfy yet')}</span></div>${breakdownHtml}</div>`);
      const actions = `<span class="alogw-req-actions"><button class="alogw-mini-btn alogw-mini-btn--edit" type="button" data-edit-category="${esc(categoryId)}">Edit</button><button class="alogw-mini-btn alogw-mini-btn--delete" type="button" data-delete-category="${esc(categoryId)}">Delete</button></span>`;
      return `<div class="alogw-req-row"><span class="alogw-req-cell" title="${esc(r.authority)}">${esc(r.authority)}</span><strong class="alogw-req-cell" title="${esc(r.label)}">${esc(r.label)}</strong><span class="alogw-req-value">${esc(formatRequirementValue(required, unit))}</span><span class="alogw-req-value">${esc(formatRequirementValue(actual, unit))}</span><span class="alogw-badge alogw-badge--${status === 'PASS' ? 'pass':'fail'}" title="${esc(result)}">${esc(result)}</span>${actions}${evidenceHtml}</div>`;
    }).join('');
    document.getElementById('alogRequirements').innerHTML = reqs.length ? header + rows : '<div class="alogw-image-empty">No requirement categories.</div>';
    const cats = data.requirement_categories || [];
    document.getElementById('alogRequirementSelect').innerHTML = cats.map(c => `<option value="${esc(c.id)}">${esc(c.authority)} ${esc(c.certificate)} - ${esc(c.label)}</option>`).join('');
  }
  function render8710Summary(){
    const t = data.totals || {};
    const row = (label, values) => '<tr><th class="alogw-8710-row-head">' + esc(label) + '</th>' + values.map(value => '<td class="alogw-8710-num">' + esc(displayValue(formatTimeValue(value))) + '</td>').join('') + '</tr>';
    const airplaneTotal = Math.max(0, Number(t.total_flight_time || 0) - Number(t.fnpt_simulator_time || 0));
    const airplaneValues = [
      airplaneTotal,
      t.dual_received_time,
      t.solo_time,
      t.pic_time,
      t.dual_cross_country_time,
      t.solo_cross_country_time,
      t.pic_cross_country_time,
      t.instrument_time,
      t.night_time,
      t.night_landings,
      t.night_time,
      t.single_engine_time,
      t.multi_engine_time,
    ];
    const fstdValues = [
      t.fnpt_simulator_time,
      '',
      '',
      '',
      '',
      '',
      '',
      '',
      '',
      '',
      '',
      t.fnpt_simulator_time,
      '',
    ];
    const headers = [
      'Category',
      'Total',
      'Instruction Received',
      'Solo',
      'PIC/SIC',
      'Cross Country Instruction',
      'Cross Country Solo',
      'Cross Country PIC/SIC',
      'Instrument',
      'Night',
      'Night Takeoff/Landing',
      'Night PIC/SIC',
      'Class SEL / FSTD SE',
      'Class MEL',
    ];
    document.getElementById('alog8710Summary').innerHTML = `
      <table class="alogw-8710">
        <thead><tr>${headers.map(label => `<th>${esc(label)}</th>`).join('')}</tr></thead>
        <tbody>
          ${row('Airplanes', airplaneValues)}
          ${row('FFS / FTD / ATD', fstdValues)}
        </tbody>
      </table>
      <div class="alogw-8710-note">Variables remain available to forms and APIs, but are no longer shown permanently on this page.</div>
    `;
  }
  function requirementBreakdownHtml(req){
    const b = req && req.breakdown && typeof req.breakdown === 'object' ? req.breakdown : null;
    if(!b || req.rule_type !== 'credited_total_time') return '';
    const rows = [
      ['Airplane time', b.airplane_time],
      ['AATD/BATD logged', b.sim_logged_time],
      ['AATD/BATD credited', b.sim_credited_time],
      ['AATD/BATD cap', b.sim_credit_cap],
      ['Simulator time not credited', b.sim_excess_time],
      ['Credited total', b.credited_total_time],
    ];
    return `<ul class="alogw-req-evidence-list">${rows.map(([label,value]) => `<li><strong>${esc(label)}:</strong><span>${esc(formatRequirementValue(value, 'h'))}</span></li>`).join('')}</ul>`;
  }
  function formatTimeValue(value){
    if(value === null || value === undefined || value === '') return '';
    const number = Number(value);
    if(Number.isNaN(number) || number === 0) return '';
    return number.toFixed(2);
  }
  function requirementUnit(req){
    const metric = String(req.metric || '').toLowerCase();
    const key = String(req.requirement_key || '').toLowerCase();
    const label = String(req.label || '').toLowerCase();
    const warnings = Array.isArray(req.warnings) ? req.warnings.join(' ').toLowerCase() : '';
    if(metric.includes('distance') || key.includes('distance') || label.includes('distance') || label.includes('nm')) return 'nm';
    if(metric.includes('landing') || key.includes('landing') || label.includes('landing') || warnings.includes('manual assignment')) return 'count';
    return 'h';
  }
  function formatRequirementValue(value, unit){
    if(value === null || value === undefined || Number.isNaN(Number(value))) return '-';
    const number = Number(value);
    if(unit === 'h') return `${number.toFixed(1)} h`;
    if(unit === 'nm') return `${number.toFixed(1)} NM`;
    return String(Math.round(number));
  }
  function renderVariables(){
    const box = document.getElementById('alogVariables');
    if(!box) return;
    const vars = data.variables || {};
    const iacra = data.iacra_8710 || {};
    const blocks = [];
    Object.keys(iacra).forEach(k => blocks.push([`iacra.${k}`, iacra[k]]));
    Object.keys(vars).sort().forEach(k => blocks.push([k, vars[k]]));
    box.innerHTML = blocks.map(([k,v]) => `<div class="alogw-var">{{${esc(k)}}}: ${esc(v ?? '')}</div>`).join('');
  }
  function selectedIndexes(){ return [...document.querySelectorAll('[data-select]:checked')].map(el => Number(el.dataset.select)).filter(i => entries[i]); }
  function setRowSelection(predicate){
    document.querySelectorAll('[data-select]').forEach(el => {
      const index = Number(el.dataset.select);
      el.checked = predicate(entries[index], index);
    });
  }
  document.addEventListener('change', e => {
    if(e.target.id === 'alogPageSelect') { renderExtractionStatus(); renderImage(); }
    if(e.target.id === 'alogTimeZoneSelect') { saveTimeZone(e.target.value); renderTable(); }
  });
  document.addEventListener('mousedown', e => {
    const target = e.target;
    if(!target.classList || !target.classList.contains('alogw-col-resizer')) return;
    e.preventDefault();
    const key = target.dataset.resizeCol;
    const startX = e.clientX;
    const startWidth = columnWidth(key);
    const onMove = event => {
      columnWidths[key] = Math.max(24, startWidth + event.clientX - startX);
      applyColumnWidth(key);
    };
    const onUp = () => {
      saveColumnWidths();
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
  document.addEventListener('click', async e => {
    const editIdx = e.target.dataset ? e.target.dataset.edit : undefined;
    const deleteIdx = e.target.dataset ? e.target.dataset.delete : undefined;
    const editCategoryId = e.target.dataset ? e.target.dataset.editCategory : undefined;
    const deleteCategoryId = e.target.dataset ? e.target.dataset.deleteCategory : undefined;
    if(editCategoryId !== undefined) {
      openRequirementCategoryModal(categoryById(editCategoryId));
      return;
    }
    if(deleteCategoryId !== undefined) {
      const category = categoryById(deleteCategoryId);
      if(!category) {
        setStatus('Requirement category not found');
        return;
      }
      const ok = window.confirm(`Delete requirement category "${category.label}"? Existing tagged records/evaluations for this category will also be removed.`);
      if(!ok) return;
      try {
        await post({action:'delete_requirement_category', logbook_id:logbookId, requirement_category_id:category.id});
        setStatus('Requirement category deleted');
      } catch(err){ setStatus(err.message); }
      return;
    }
    if(editIdx !== undefined) {
      openEditModal(Number(editIdx));
    }
    if(deleteIdx !== undefined) {
      const row = entries[Number(deleteIdx)];
      if(row && row.id) {
        try { await post({action:'delete_entry', logbook_id:logbookId, entry_id:row.id}); setStatus('Deleted'); } catch(err){ setStatus(err.message); }
      }
    }
  });
  function openEditModal(index){
    const row = entries[index] ? {...entries[index]} : {review_status:'accepted'};
    document.getElementById('alogEditForm').dataset.index = String(index);
    document.getElementById('alogEditFields').innerHTML = editFields.map(([key,label,type]) => editField(row,key,label,type)).join('');
    document.getElementById('alogEditModal').classList.add('is-open');
    document.getElementById('alogEditModal').setAttribute('aria-hidden','false');
  }
  function closeEditModal(){
    document.getElementById('alogEditModal').classList.remove('is-open');
    document.getElementById('alogEditModal').setAttribute('aria-hidden','true');
  }
  function editField(row,key,label,type){
    const value = row[key] ?? '';
    const wide = type === 'textarea' ? ' alogw-edit-field--wide' : '';
    if(type === 'textarea') return `<label class="alogw-edit-field${wide}"><span class="alogw-edit-label">${esc(label)}</span><textarea class="alogw-edit-textarea" name="${esc(key)}">${esc(value)}</textarea></label>`;
    if(type === 'status') return `<label class="alogw-edit-field"><span class="alogw-edit-label">${esc(label)}</span><select class="alogw-edit-select" name="${esc(key)}">${['imported','needs_review','accepted','rejected','ok','flagged','merged','split'].map(s => `<option value="${s}"${String(value)===s?' selected':''}>${s.replace('_',' ')}</option>`).join('')}</select></label>`;
    const step = type === 'number' ? ' step="0.01"' : '';
    return `<label class="alogw-edit-field"><span class="alogw-edit-label">${esc(label)}</span><input class="alogw-edit-input" type="${esc(type)}"${step} name="${esc(key)}" value="${esc(value)}"></label>`;
  }
  document.getElementById('alogCancelEdit').addEventListener('click', closeEditModal);
  document.getElementById('alogEditForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const index = Number(e.currentTarget.dataset.index || -1);
      const base = entries[index] ? {...entries[index]} : {review_status:'accepted'};
      const formData = new FormData(e.currentTarget);
      editFields.forEach(([key,,type]) => {
        const value = formData.get(key);
        base[key] = type === 'number' ? Number(value || 0) : String(value || '');
      });
      await post({action:'save_entry', logbook_id:logbookId, entry:base});
      closeEditModal();
      setStatus('Row saved');
    } catch(err){ setStatus(err.message); }
  });
  function defaultRequirementCategory(){
    return {
      id:'',
      authority:'FAA_PART_61',
      certificate:'PPL',
      requirement_key:'',
      status:'active',
      label:'',
      description:'',
      minimum_time:'',
      minimum_distance_nm:'',
      minimum_count:'',
      allow_one_flight_multiple_requirements:1,
      allow_multiple_flights_one_requirement:1,
      evaluation_method:'manual_assignment',
      selected_entry_metric:'',
      total_metric:'',
      filter_preset:'',
      sim_credit_cap:'2.5',
      automatic_rules_json:'{}',
      manual_rules_json:'{}'
    };
  }
  function openRequirementCategoryModal(category){
    const row = {...defaultRequirementCategory(), ...(category || {})};
    const rules = decodeJsonObject(row.automatic_rules_json);
    if(rules.type === 'selected_entries_sum') {
      row.evaluation_method = 'selected_entries_sum';
      row.selected_entry_metric = rules.metric || rules.field || '';
    } else if(rules.type === 'credited_total_time') {
      row.evaluation_method = 'credited_total_time';
      row.sim_credit_cap = rules.sim_credit_cap || '2.5';
    } else if(rules.type === 'filtered_sum') {
      row.evaluation_method = 'filtered_sum';
      row.selected_entry_metric = rules.metric || '';
      row.filter_preset = filterPresetFromRules(rules.filters || []);
    } else if(rules.type === 'selected_entries_distance') {
      row.evaluation_method = 'selected_entries_distance';
      row.selected_entry_metric = 'cross_country_distance_nm';
    } else if(rules.metric) {
      row.evaluation_method = 'total_metric';
      row.total_metric = rules.metric || '';
    } else if(inferredSelectedEntryMetric(row.requirement_key)) {
      row.evaluation_method = 'selected_entries_sum';
      row.selected_entry_metric = inferredSelectedEntryMetric(row.requirement_key);
    } else {
      row.evaluation_method = 'manual_assignment';
    }
    const form = document.getElementById('alogRequirementCategoryForm');
    document.getElementById('alogRequirementCategoryTitle').textContent = row.id ? 'Edit Requirement Category' : 'Add Requirement Category';
    Object.keys(defaultRequirementCategory()).forEach(key => {
      if(!form.elements[key]) return;
      let value = row[key] ?? '';
      if((key === 'automatic_rules_json' || key === 'manual_rules_json') && value && typeof value !== 'string') {
        value = JSON.stringify(value, null, 2);
      }
      if((key === 'automatic_rules_json' || key === 'manual_rules_json') && String(value).trim() === '') {
        value = '{}';
      }
      form.elements[key].value = String(value);
    });
    updateRequirementRuleControls();
    document.getElementById('alogRequirementCategoryModal').classList.add('is-open');
    document.getElementById('alogRequirementCategoryModal').setAttribute('aria-hidden','false');
  }
  function closeRequirementCategoryModal(){
    document.getElementById('alogRequirementCategoryModal').classList.remove('is-open');
    document.getElementById('alogRequirementCategoryModal').setAttribute('aria-hidden','true');
  }
  document.getElementById('alogAddRequirementCategory').addEventListener('click', () => openRequirementCategoryModal(null));
  document.getElementById('alogCancelRequirementCategory').addEventListener('click', closeRequirementCategoryModal);
  function updateRequirementRuleControls(){
    const form = document.getElementById('alogRequirementCategoryForm');
    const method = form.elements.evaluation_method ? String(form.elements.evaluation_method.value || '') : '';
    if(form.elements.selected_entry_metric) {
      form.elements.selected_entry_metric.disabled = !['selected_entries_sum','filtered_sum'].includes(method);
    }
    if(form.elements.total_metric) {
      form.elements.total_metric.disabled = method !== 'total_metric';
    }
    if(form.elements.filter_preset) {
      form.elements.filter_preset.disabled = method !== 'filtered_sum';
    }
    if(form.elements.sim_credit_cap) {
      form.elements.sim_credit_cap.disabled = method !== 'credited_total_time';
    }
  }
  document.getElementById('alogRequirementEvaluationMethod').addEventListener('change', updateRequirementRuleControls);
  function filterRulesForPreset(preset){
    const filters = {
      dual: [{field:'dual_received_time', operator:'gt', value:0}],
      solo: [{field:'solo_time', operator:'gt', value:0}],
      cross_country: [{field:'cross_country_time', operator:'gt', value:0}],
      night: [{field:'night_time', operator:'gt', value:0}],
      dual_cross_country: [{field:'dual_received_time', operator:'gt', value:0}, {field:'cross_country_time', operator:'gt', value:0}],
      solo_cross_country: [{field:'solo_time', operator:'gt', value:0}, {field:'cross_country_time', operator:'gt', value:0}],
      dual_night: [{field:'dual_received_time', operator:'gt', value:0}, {field:'night_time', operator:'gt', value:0}],
      solo_towered: [{field:'solo_time', operator:'gt', value:0}, {field:'towered_airport_landings', operator:'gt', value:0}]
    };
    return filters[preset] || [];
  }
  function filterPresetFromRules(filters){
    const normalized = JSON.stringify((filters || []).map(f => ({field:f.field || '', operator:f.operator || 'gt', value:Number(f.value || 0)})));
    for(const preset of ['dual','solo','cross_country','night','dual_cross_country','solo_cross_country','dual_night','solo_towered']) {
      if(JSON.stringify(filterRulesForPreset(preset)) === normalized) return preset;
    }
    return '';
  }
  document.getElementById('alogRequirementCategoryForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const formData = new FormData(e.currentTarget);
      const payload = Object.fromEntries(formData.entries());
      payload.action = 'save_requirement_category';
      payload.id = Number(payload.id || 0);
      payload.minimum_time = payload.minimum_time === '' ? null : Number(payload.minimum_time);
      payload.minimum_distance_nm = payload.minimum_distance_nm === '' ? null : Number(payload.minimum_distance_nm);
      payload.minimum_count = payload.minimum_count === '' ? null : Number(payload.minimum_count);
      payload.allow_one_flight_multiple_requirements = String(payload.allow_one_flight_multiple_requirements || '0') === '1';
      payload.allow_multiple_flights_one_requirement = String(payload.allow_multiple_flights_one_requirement || '0') === '1';
      const method = String(payload.evaluation_method || 'manual_assignment');
      if(method === 'selected_entries_sum') {
        if(!payload.selected_entry_metric) throw new Error('Choose which tagged entry field to sum.');
        payload.automatic_rules_json = JSON.stringify({type:'selected_entries_sum', metric:payload.selected_entry_metric});
      } else if(method === 'filtered_sum') {
        if(!payload.selected_entry_metric) throw new Error('Choose which logbook metric to sum.');
        payload.automatic_rules_json = JSON.stringify({type:'filtered_sum', metric:payload.selected_entry_metric, filters:filterRulesForPreset(payload.filter_preset || '')});
      } else if(method === 'credited_total_time') {
        payload.minimum_time = payload.minimum_time === null ? 40 : payload.minimum_time;
        payload.automatic_rules_json = JSON.stringify({type:'credited_total_time', sim_metric:'fnpt_simulator_time', sim_credit_cap:Number(payload.sim_credit_cap || 2.5)});
      } else if(method === 'selected_entries_distance') {
        payload.automatic_rules_json = JSON.stringify({type:'selected_entries_distance'});
        payload.manual_rules_json = JSON.stringify({evidence:'selected_logbook_entries', requires_distance_nm:true});
      } else if(method === 'total_metric') {
        if(!payload.total_metric) throw new Error('Choose the total logbook metric.');
        payload.automatic_rules_json = JSON.stringify({metric:payload.total_metric});
      } else {
        payload.automatic_rules_json = JSON.stringify({type:'manual_assignment'});
      }
      payload.manual_rules_json = payload.manual_rules_json || JSON.stringify({evidence:'selected_logbook_entries'});
      await post(payload);
      await refreshWorkspace();
      closeRequirementCategoryModal();
      setStatus('Requirement category saved');
    } catch(err){ setStatus(err.message); }
  });
  function openEgleSettings(){
    document.getElementById('alogEgleSettingsModal').classList.add('is-open');
    document.getElementById('alogEgleSettingsModal').setAttribute('aria-hidden','false');
  }
  function closeEgleSettings(){
    document.getElementById('alogEgleSettingsModal').classList.remove('is-open');
    document.getElementById('alogEgleSettingsModal').setAttribute('aria-hidden','true');
  }
  document.getElementById('alogOpenEgleSettings').addEventListener('click', openEgleSettings);
  document.getElementById('alogCloseEgleSettings').addEventListener('click', closeEgleSettings);
  document.getElementById('alogEgleConnectionForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      setStatus('Testing E-GLE connection...');
      const form = new FormData(e.currentTarget);
      const payload = Object.fromEntries(form.entries());
      payload.action = 'test_connection';
      payload.ssl = payload.ssl === '1';
      const json = await eglePost(payload);
      await loadEgleStatus();
      const tables = json.result && json.result.tables ? json.result.tables.join(', ') : 'no expected tables found';
      closeEgleSettings();
      setStatus('E-GLE connected. Tables: ' + tables);
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogDisconnectEgle').addEventListener('click', async () => {
    try {
      await eglePost({action:'disconnect'});
      await loadEgleStatus();
      setStatus('E-GLE disconnected');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogSearchEgleUsers').addEventListener('click', async () => {
    try {
      const query = document.getElementById('alogEgleSearchQuery').value || (data.logbook ? data.logbook.student_email : '');
      const json = await eglePost({action:'search_users', query, ipca_user_id:data.logbook.student_user_id});
      renderEgleCandidates(json.candidates || []);
      setStatus('E-GLE candidates loaded');
    } catch(err){ setStatus(err.message); }
  });
  function renderEgleCandidates(candidates){
    const box = document.getElementById('alogEgleCandidates');
    box.innerHTML = candidates.length ? candidates.map(c => `<div class="alogw-egle-candidate"><div><strong>${esc(c.egle_full_name || c.egle_userid)}</strong><br><span>${esc(c.egle_email || '')} · userid ${esc(c.egle_userid)} · confidence ${esc(c.confidence_score)}</span></div><button class="alogw-btn alogw-btn--secondary" type="button" data-map-egle="${esc(c.egle_userid)}" data-map-email="${esc(c.egle_email || '')}" data-map-name="${esc(c.egle_full_name || '')}" data-map-confidence="${esc(c.confidence_score || '')}">Map</button></div>`).join('') : '<div class="alogw-panel-text">No E-GLE candidates found.</div>';
  }
  document.addEventListener('click', async e => {
    const target = e.target;
    if(!target.dataset || target.dataset.mapEgle === undefined) return;
    try {
      await eglePost({
        action:'save_mapping',
        ipca_user_id:data.logbook.student_user_id,
        egle_userid:target.dataset.mapEgle,
        egle_email:target.dataset.mapEmail || '',
        egle_full_name:target.dataset.mapName || '',
        confidence_score:target.dataset.mapConfidence || null,
        mapping_type:'confirmed'
      });
      setStatus('E-GLE mapping saved');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogDeleteMapping').addEventListener('click', async () => {
    try {
      await eglePost({action:'delete_mapping', ipca_user_id:data.logbook.student_user_id});
      document.getElementById('alogEgleCandidates').innerHTML = '';
      setStatus('E-GLE mapping deleted');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogSyncStudent').addEventListener('click', async () => {
    try {
      setStatus('Syncing E-GLE student...');
      const json = await eglePost({action:'sync_student', ipca_user_id:data.logbook.student_user_id});
      await refreshWorkspace();
      const result = json.result || {};
      setStatus(`E-GLE sync complete: ${result.imported_count || 0} imported, ${result.changed_count || 0} changed, ${result.unchanged_count || 0} unchanged`);
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogSyncAll').addEventListener('click', async () => {
    try {
      setStatus('Syncing all mapped E-GLE students...');
      const json = await eglePost({action:'sync_all_students'});
      await refreshWorkspace();
      const result = json.result || {};
      setStatus(`E-GLE sync all complete: ${result.imported_count || 0} imported, ${result.changed_count || 0} changed`);
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogReviewChanges').addEventListener('click', async () => {
    try {
      const json = await eglePost({action:'review_changes', logbook_id:logbookId});
      const result = json.result || {};
      setStatus(`Pending E-GLE review rows: ${result.pending_review_count || 0}`);
    } catch(err){ setStatus(err.message); }
  });
  function openImageModal(){
    document.getElementById('alogImageModal').classList.add('is-open');
    document.getElementById('alogImageModal').setAttribute('aria-hidden','false');
    renderImage();
  }
  function closeImageModal(){
    document.getElementById('alogImageModal').classList.remove('is-open');
    document.getElementById('alogImageModal').setAttribute('aria-hidden','true');
  }
  document.getElementById('alogViewImage').addEventListener('click', openImageModal);
  document.getElementById('alogCloseImage').addEventListener('click', closeImageModal);
  document.getElementById('alogZoomIn').addEventListener('click', () => { zoom += .1; renderImage(); });
  document.getElementById('alogZoomOut').addEventListener('click', () => { zoom = Math.max(.4, zoom - .1); renderImage(); });
  document.getElementById('alogShowRequirements').addEventListener('click', () => document.getElementById('alogRequirements').scrollIntoView({behavior:'smooth', block:'start'}));
  document.getElementById('alogShowIacra').addEventListener('click', () => document.getElementById('alogVariables').scrollIntoView({behavior:'smooth', block:'start'}));
  document.getElementById('alogShow8710').addEventListener('click', () => document.getElementById('alogVariables').scrollIntoView({behavior:'smooth', block:'start'}));
  document.getElementById('alogExtractPage').addEventListener('click', async () => {
    try {
      const page = currentPage();
      if(!page) { setStatus('Upload a source page first'); return; }
      setStatus('Running import/extract...');
      const json = await post({action:'attempt_extract_page', logbook_id:logbookId, page_id:page.id});
      const result = json.result || {};
      const count = Number(result.candidate_count || 0);
      if (result.already_imported) {
        setStatus('This page already has imported candidate rows');
      } else {
        setStatus(count > 0 ? `Imported ${count} candidate row${count === 1 ? '' : 's'} for review` : 'Extraction completed: no readable rows detected');
      }
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogAddRow').addEventListener('click', () => openEditModal(-1));
  document.getElementById('alogSelectAllRows').addEventListener('click', () => {
    setRowSelection(() => true);
    setStatus('All visible rows selected');
  });
  document.getElementById('alogSelectReviewRows').addEventListener('click', () => {
    const reviewStatuses = new Set(['imported','needs_review','flagged']);
    setRowSelection(row => row && reviewStatuses.has(String(row.review_status || '')));
    setStatus('Imported/review rows selected');
  });
  document.getElementById('alogClearSelection').addEventListener('click', () => {
    setRowSelection(() => false);
    setStatus('Selection cleared');
  });
  function openBulkEditModal(){
    if(selectedIndexes().length === 0) {
      setStatus('Select one or more rows first');
      return;
    }
    document.getElementById('alogBulkEditModal').classList.add('is-open');
    document.getElementById('alogBulkEditModal').setAttribute('aria-hidden','false');
  }
  function closeBulkEditModal(){
    document.getElementById('alogBulkEditModal').classList.remove('is-open');
    document.getElementById('alogBulkEditModal').setAttribute('aria-hidden','true');
  }
  document.getElementById('alogOpenBulkEdit').addEventListener('click', openBulkEditModal);
  document.getElementById('alogCancelBulkEdit').addEventListener('click', closeBulkEditModal);
  document.getElementById('alogBulkEditForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      const formData = new FormData(e.currentTarget);
      const flags = Object.fromEntries(formData.entries());
      await post({action:'bulk_update_entries', logbook_id:logbookId, entry_ids:ids, flags});
      closeBulkEditModal();
      setStatus('Bulk edit applied');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogAcceptRows').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'accept_entries', logbook_id:logbookId, entry_ids:ids});
      setStatus('Accepted selected rows');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogRejectRows').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'reject_entries', logbook_id:logbookId, entry_ids:ids});
      setStatus('Rejected selected rows');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogDeleteRows').addEventListener('click', async () => {
    try {
      const indexes = selectedIndexes();
      if(indexes.length === 0) {
        setStatus('Select one or more rows first');
        return;
      }
      const ids = indexes.map(i => Number(entries[i].id || 0)).filter(Boolean);
      const unsaved = indexes.filter(i => !entries[i].id).sort((a,b) => b-a);
      unsaved.forEach(i => entries.splice(i, 1));
      if(ids.length > 0) {
        await post({action:'delete_entries', logbook_id:logbookId, entry_ids:ids});
      } else {
        renderTable();
      }
      setStatus(`Deleted ${indexes.length} selected row${indexes.length === 1 ? '' : 's'}`);
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogFlagRows').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'flag_entries', logbook_id:logbookId, entry_ids:ids});
      setStatus('Flagged selected rows');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogSplitRow').addEventListener('click', async () => {
    try {
      const [i] = selectedIndexes();
      if(i === undefined || !entries[i].id) return;
      await post({action:'split_entry', logbook_id:logbookId, entry_id:entries[i].id});
      setStatus('Split selected row');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogMergeRows').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      await post({action:'merge_entries', logbook_id:logbookId, entry_ids:ids});
      setStatus('Merged selected rows');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogAssignRequirement').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      if (!ids.length) {
        throw new Error('Select one or more logbook rows to tag as the required event.');
      }
      await post({action:'assign_requirement', logbook_id:logbookId, student_user_id:data.logbook.student_user_id, requirement_category_id:document.getElementById('alogRequirementSelect').value, entry_ids:ids});
      setStatus('Selected logbook row(s) tagged as required event');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogUnassignRequirement').addEventListener('click', async () => {
    try {
      const ids = selectedIndexes().map(i => Number(entries[i].id || 0)).filter(Boolean);
      if (!ids.length) {
        throw new Error('Select one or more logbook rows to untag.');
      }
      const select = document.getElementById('alogRequirementSelect');
      const label = select.options[select.selectedIndex] ? select.options[select.selectedIndex].textContent : 'this event';
      if (!window.confirm(`Remove selected row(s) from ${label}?`)) {
        return;
      }
      const json = await post({action:'unassign_requirement', logbook_id:logbookId, requirement_category_id:select.value, entry_ids:ids});
      const count = Number(json.deleted_count || 0);
      setStatus(count > 0 ? `Removed ${count} tagged row${count === 1 ? '' : 's'} from requirement event` : 'No matching tags found for selected row(s)');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogClearRequirementTags').addEventListener('click', async () => {
    try {
      const ok = window.confirm('Remove ALL requirement tags from this logbook? Automatic requirements will still calculate from accepted rows, but manual/event evidence will need to be re-tagged.');
      if (!ok) {
        return;
      }
      const json = await post({action:'clear_requirement_tags', logbook_id:logbookId});
      const result = json.result || {};
      const links = Number(result.entry_link_count || 0);
      const assignments = Number(result.assignment_count || 0);
      setStatus(`Removed all requirement tags: ${links} tagged row link${links === 1 ? '' : 's'} across ${assignments} assignment${assignments === 1 ? '' : 's'}`);
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogUploadForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      setStatus('Uploading...');
      const res = await fetch(api, {method:'POST', body:new FormData(e.currentTarget)});
      const json = await res.json();
      if(!json.ok) throw new Error(json.error || 'Upload failed');
      data = json.data; entries = (data.entries || []).map(row => ({...row, _dirty:false})); renderAll(); setStatus('Uploaded');
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogCsvForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      setStatus('Importing CSV...');
      const res = await fetch(api, {method:'POST', body:new FormData(e.currentTarget)});
      const json = await res.json();
      if(!json.ok) throw new Error(json.error || 'CSV import failed');
      data = json.data; entries = (data.entries || []).map(row => ({...row, _dirty:false})); renderAll();
      const count = json.result ? Number(json.result.imported_count || 0) : 0;
      setStatus(`Imported ${count} CSV candidate row${count === 1 ? '' : 's'}`);
    } catch(err){ setStatus(err.message); }
  });
  document.getElementById('alogTimeZoneSelect').value = selectedTimeZone;
  renderAll();
  loadEgleStatus();
})();
</script>

<?php cw_footer(); ?>
