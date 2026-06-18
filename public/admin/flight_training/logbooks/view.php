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
.alogw-table-wrap{overflow-x:hidden;overflow-y:auto;max-height:720px;background:#fff}.alogw-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:0;background:#fff}.alogw-table th{position:sticky;top:0;z-index:1;padding:6px 3px;background:#f8fafc;border-right:1px solid #e2e8f0;border-bottom:1px solid #cbd5e1;font-size:8px;line-height:1;white-space:nowrap;text-transform:uppercase;letter-spacing:0;color:#475569;text-align:center}.alogw-table td{padding:5px 3px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;vertical-align:middle;background:#fff;font-size:9px;line-height:1.1;color:#102845;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.alogw-table tr:nth-child(even) td{background:#f8fafc}.alogw-table input[type=checkbox]{width:auto;height:auto}.alogw-row-actions{display:flex;gap:4px;justify-content:center}.alogw-mini-btn{border:0;border-radius:999px;padding:3px 6px;font-size:8px;font-weight:900;cursor:pointer}.alogw-mini-btn--edit{background:#eef2ff;color:#1e3a8a}.alogw-mini-btn--delete{background:#fff1f2;color:#be123c}.alogw-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 6px;font-size:8px;font-weight:900;text-transform:uppercase;background:#e2e8f0;color:#475569}.alogw-status-pill--accepted,.alogw-status-pill--ok{background:#dcfce7;color:#166534}.alogw-status-pill--imported,.alogw-status-pill--needs_review,.alogw-status-pill--flagged{background:#fef3c7;color:#92400e}.alogw-status-pill--rejected{background:#fee2e2;color:#991b1b}
.alogw-modal{position:fixed;inset:0;z-index:9999;display:none;background:rgba(15,23,42,.62);padding:28px}.alogw-modal.is-open{display:flex;align-items:center;justify-content:center}.alogw-modal-card{width:min(1100px,96vw);height:min(820px,92vh);border-radius:22px;background:#fff;box-shadow:0 28px 80px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden}.alogw-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(15,23,42,.08)}.alogw-modal-body{flex:1;background:#0f172a;overflow:auto;display:flex;align-items:center;justify-content:center}.alogw-modal-body img{max-width:100%;transform-origin:center;transition:transform .15s ease}.alogw-image-empty{color:#cbd5e1;text-align:center;padding:28px;font-size:13px}
.alogw-edit-body{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:16px;overflow:auto;background:#fff}.alogw-edit-field{display:flex;flex-direction:column;gap:5px}.alogw-edit-field--wide{grid-column:1/-1}.alogw-edit-label{font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748b}.alogw-edit-input,.alogw-edit-textarea,.alogw-edit-select{border:1px solid rgba(15,23,42,.13);border-radius:12px;padding:9px 10px;font:inherit;font-size:13px;color:#102845;background:#fff}.alogw-edit-textarea{min-height:74px;resize:vertical}.alogw-edit-actions{display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;border-top:1px solid rgba(15,23,42,.08);background:#f8fafc}
.alogw-bottom{display:grid;grid-template-columns:1fr 1fr;gap:16px}.alogw-req-list{max-height:360px;overflow:auto}.alogw-req-row{display:grid;grid-template-columns:86px 1fr 70px;gap:10px;padding:10px 16px;border-bottom:1px solid rgba(15,23,42,.06);align-items:center}.alogw-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:900;text-transform:uppercase}.alogw-badge--pass{background:#dcfce7;color:#166534}.alogw-badge--fail{background:#fee2e2;color:#991b1b}
.alogw-vars{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;padding:12px 16px;max-height:360px;overflow:auto}.alogw-var{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);border-radius:10px;padding:7px;color:#334155}
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
      <select class="alogw-select" id="alogRequirementSelect"></select>
      <button class="alogw-btn alogw-btn--secondary" type="button" id="alogAssignRequirement">Assign Requirement</button>
    </div>
    <div class="alogw-table-wrap">
      <table class="alogw-table">
        <thead id="alogTableHead"></thead>
        <tbody id="alogTableBody"></tbody>
      </table>
    </div>
  </section>

  <section class="alogw-bottom">
    <div class="alogw-panel">
      <div class="alogw-panel-head">
        <div>
          <h2 class="alogw-panel-title">Requirement Verification</h2>
          <p class="alogw-panel-text">PASS/FAIL preview based on totals and explicit assignments.</p>
        </div>
      </div>
      <div class="alogw-req-list" id="alogRequirements"></div>
    </div>
    <div class="alogw-panel">
      <div class="alogw-panel-head">
        <div>
          <h2 class="alogw-panel-title">IACRA / FAA 8710 + Variables</h2>
          <p class="alogw-panel-text">Generated data for future FAA/EASA form auto-fill.</p>
        </div>
      </div>
      <div class="alogw-vars" id="alogVariables"></div>
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
        <label class="alogw-edit-field"><span class="alogw-edit-label">Night</span><select class="alogw-edit-select" name="night_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
        <label class="alogw-edit-field"><span class="alogw-edit-label">Instrument</span><select class="alogw-edit-select" name="instrument_time"><option value="">Keep</option><option value="1">Set total time</option><option value="0">Clear</option></select></label>
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
  const cols = [
    ['select',''],['actions','Actions'],['entry_date','Date'],['departure_airport','Departure'],['arrival_airport','Arrival'],
    ['aircraft_registration','Aircraft Reg'],['aircraft_type','Aircraft Type'],['single_engine_time','SE'],['multi_engine_time','ME'],
    ['pic_time','PIC'],['copilot_time','Co-Pilot'],['dual_received_time','Dual'],['solo_time','Solo'],['cross_country_time','XC'],
    ['cross_country_distance_nm','XC NM'],['day_time','Day Time'],['night_time','Night Time'],['instrument_time','Instrument'],['basic_instrument_flying_time','Basic Instr'],
    ['fnpt_simulator_time','FNPT / Sim'],['day_landings','Day Ldg'],['night_landings','Night Ldg'],['instructor_name','Instructor'],
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
  const numeric = new Set(['single_engine_time','multi_engine_time','pic_time','copilot_time','dual_received_time','solo_time','cross_country_time','cross_country_distance_nm','night_time','instrument_time','actual_instrument_time','simulated_instrument_time','basic_instrument_flying_time','fnpt_simulator_time','day_landings','night_landings','towered_airport_landings','total_flight_time']);
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
  function renderAll(){ renderCards(); renderPages(); renderTable(); renderRequirements(); renderVariables(); }
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
    document.getElementById('alogTableHead').innerHTML = '<tr>' + cols.map(([,label]) => `<th>${esc(label)}</th>`).join('') + '</tr>';
    document.getElementById('alogTableBody').innerHTML = entries.map((row,i) => '<tr data-i="'+i+'">' + cols.map(([key]) => cell(row,i,key)).join('') + '</tr>').join('');
  }
  function cell(row,i,key){
    if(key === 'select') return `<td><input type="checkbox" data-select="${i}"></td>`;
    if(key === 'actions') return `<td><div class="alogw-row-actions"><button class="alogw-mini-btn alogw-mini-btn--edit" type="button" data-edit="${i}">Edit</button><button class="alogw-mini-btn alogw-mini-btn--delete" type="button" data-delete="${i}">Delete</button></div></td>`;
    if(key === 'review_status') {
      const status = String(row[key] || 'ok');
      return `<td><span class="alogw-status-pill alogw-status-pill--${esc(status)}">${esc(status.replace('_',' '))}</span></td>`;
    }
    return `<td title="${esc(row[key] || '')}">${esc(displayValue(row[key]))}</td>`;
  }
  function displayValue(value){
    if(value === 0 || value === '0' || value === 0.0 || value === '0.00') return '0';
    if(value === null || value === undefined || value === '') return '';
    return String(value);
  }
  function renderRequirements(){
    const reqs = data.requirements || [];
    document.getElementById('alogRequirements').innerHTML = reqs.map(r => `<div class="alogw-req-row"><span>${esc(r.authority)}</span><strong>${esc(r.label)}</strong><span class="alogw-badge alogw-badge--${r.status === 'pass' ? 'pass':'fail'}">${esc(r.status)}</span></div>`).join('') || '<div class="alogw-image-empty">No requirement categories.</div>';
    const cats = data.requirement_categories || [];
    document.getElementById('alogRequirementSelect').innerHTML = cats.map(c => `<option value="${esc(c.id)}">${esc(c.authority)} ${esc(c.certificate)} - ${esc(c.label)}</option>`).join('');
  }
  function renderVariables(){
    const vars = data.variables || {};
    const iacra = data.iacra_8710 || {};
    const blocks = [];
    Object.keys(iacra).forEach(k => blocks.push([`iacra.${k}`, iacra[k]]));
    Object.keys(vars).sort().forEach(k => blocks.push([k, vars[k]]));
    document.getElementById('alogVariables').innerHTML = blocks.map(([k,v]) => `<div class="alogw-var">{{${esc(k)}}}: ${esc(v ?? '')}</div>`).join('');
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
  });
  document.addEventListener('click', async e => {
    const editIdx = e.target.dataset ? e.target.dataset.edit : undefined;
    const deleteIdx = e.target.dataset ? e.target.dataset.delete : undefined;
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
    try { for (const i of selectedIndexes().reverse()) { if(entries[i].id){ await post({action:'delete_entry', logbook_id:logbookId, entry_id:entries[i].id}); } else { entries.splice(i,1); renderTable(); } } setStatus('Deleted'); } catch(err){ setStatus(err.message); }
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
      await post({action:'assign_requirement', logbook_id:logbookId, student_user_id:data.logbook.student_user_id, requirement_category_id:document.getElementById('alogRequirementSelect').value, entry_ids:ids});
      setStatus('Requirement assigned');
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
  renderAll();
  loadEgleStatus();
})();
</script>

<?php cw_footer(); ?>
