<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

$allowedRoles = ['admin', 'supervisor', 'instructor', 'chief_instructor'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

cw_header('Instructor Theory Control Center');
?>

<style>
.tcc-page{display:flex;flex-direction:column;gap:18px;padding-bottom:24px}.tcc-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:22px;box-shadow:0 14px 30px rgba(15,23,42,.06)}.tcc-hero{padding:20px 22px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;overflow:hidden}.tcc-hero-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end}.tcc-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.16em;font-weight:900;color:rgba(255,255,255,.72)}.tcc-hero h1{margin:6px 0 0;font-size:30px;line-height:1.02;letter-spacing:-.04em;color:#fff}.tcc-hero-sub{margin-top:8px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.84);max-width:860px}.tcc-toolbar{display:flex;align-items:flex-end;gap:10px;flex:0 0 auto}.tcc-field{display:flex;flex-direction:column;gap:6px;min-width:300px}.tcc-field label{font-size:11px;font-weight:800;color:rgba(255,255,255,.78);letter-spacing:.12em;text-transform:uppercase}.tcc-select{min-height:44px;border-radius:14px;border:1px solid rgba(15,23,42,.12);background:rgba(255,255,255,.96);color:#102845;padding:10px 12px;font:inherit;font-size:14px;font-weight:400;outline:none}.tcc-section{padding:18px 20px;min-width:0;box-sizing:border-box}.tcc-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}.tcc-section-title{margin:0;font-size:20px;line-height:1.1;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-section-sub{margin-top:5px;font-size:13px;color:#64748b;line-height:1.4}.tcc-count-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 11px;background:#edf4ff;color:#1d4f91;font-size:12px;font-weight:800;border:1px solid #d3e3ff;white-space:nowrap}.tcc-health-strip{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}.tcc-health-card{border-radius:17px;background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%);border:1px solid rgba(15,23,42,.07);padding:15px 16px;min-width:0}.tcc-health-label{font-size:10px;color:#64748b;font-weight:900;text-transform:uppercase;letter-spacing:.13em;white-space:nowrap}.tcc-health-value{margin-top:8px;font-size:30px;line-height:1;font-weight:900;color:#102845;letter-spacing:-.04em}.tcc-health-note{margin-top:7px;font-size:12px;color:#64748b;line-height:1.35}.tcc-radar-wrap{position:relative;min-height:158px;margin-top:4px;padding:30px 28px 58px;border-radius:20px;background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%);border:1px solid rgba(15,23,42,.06);overflow:hidden}.tcc-radar-line{position:absolute;top:64px;left:34px;right:34px;height:9px;background:linear-gradient(90deg,#071a31 0%,#12355f 42%,#2b6dcc 100%);border-radius:999px;box-shadow:inset 0 1px 2px rgba(255,255,255,.18),0 8px 18px rgba(18,53,95,.16)}.tcc-radar-marker{position:absolute;top:84px;font-size:10px;color:#64748b;font-weight:900;white-space:nowrap}.tcc-radar-marker.start{left:34px}.tcc-radar-marker.end{right:34px}.tcc-radar-tools{position:absolute;right:20px;bottom:16px;display:flex;gap:8px;align-items:center;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);border:1px solid rgba(15,23,42,.08);border-radius:16px;padding:8px 10px;box-shadow:0 10px 24px rgba(15,23,42,.08)}.tcc-radar-tools label{font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.1em;white-space:nowrap}.tcc-student-select{min-height:36px;min-width:230px;border-radius:11px;border:1px solid rgba(15,23,42,.14);background:#fff;color:#102845;padding:7px 10px;font:inherit;font-size:13px;font-weight:400;outline:none}.tcc-radar-avatar{position:absolute;top:64px;transform:translate(-50%,-50%);width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:12px;cursor:pointer;box-shadow:0 8px 20px rgba(15,23,42,.22);background:#12355f;border:4px solid #fff;transition:transform .15s ease,box-shadow .15s ease;user-select:none;overflow:visible}.tcc-radar-avatar:hover{transform:translate(-50%,-50%) scale(1.05);box-shadow:0 12px 26px rgba(15,23,42,.30)}.tcc-radar-avatar.selected{outline:3px solid rgba(18,53,95,.28);outline-offset:4px}.tcc-radar-avatar .tcc-avatar-inner{position:relative;width:40px;height:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff}.tcc-radar-avatar img,.tcc-avatar img,.tcc-student-avatar img{width:100%;height:100%;object-fit:cover;display:block}.tcc-radar-avatar.green{border-color:#16a34a}.tcc-radar-avatar.orange{border-color:#f59e0b}.tcc-radar-avatar.red{border-color:#dc2626}.tcc-radar-avatar.blue{border-color:#2563eb}.tcc-radar-avatar.purple{border-color:#7c3aed}.tcc-radar-score{position:absolute;top:-29px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:900;color:#334155;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:999px;padding:3px 7px;white-space:nowrap}.tcc-radar-label{position:absolute;top:52px;left:50%;transform:translateX(-50%);font-size:11px;font-weight:900;color:#334155;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis}.tcc-radar-legend{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}.tcc-legend-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 9px;background:#f8fafc;border:1px solid rgba(15,23,42,.06);font-size:11px;font-weight:800;color:#475569}.tcc-dot{width:9px;height:9px;border-radius:999px;display:inline-block}.tcc-dot.green{background:#16a34a}.tcc-dot.orange{background:#f59e0b}.tcc-dot.red{background:#dc2626}.tcc-dot.blue{background:#2563eb}.tcc-dot.purple{background:#7c3aed}.tcc-student-placeholder{padding:18px;border-radius:18px;border:1px dashed rgba(15,23,42,.16);background:#fbfdff;color:#64748b;font-size:14px;line-height:1.5}.tcc-student-head{display:flex;align-items:center;gap:14px;margin-bottom:14px}.tcc-student-avatar{width:58px;height:58px;border-radius:20px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;flex:0 0 auto;overflow:hidden;border:4px solid #dbe7f4}.tcc-student-avatar.green{border-color:#16a34a}.tcc-student-avatar.orange{border-color:#f59e0b}.tcc-student-avatar.red{border-color:#dc2626}.tcc-student-avatar.blue{border-color:#2563eb}.tcc-student-avatar.purple{border-color:#7c3aed}.tcc-student-name{font-size:24px;line-height:1.05;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-student-email{margin-top:5px;font-size:13px;color:#64748b}.tcc-student-state-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.tcc-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 10px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);font-size:12px;font-weight:900;color:#334155}.tcc-status-pill.strong{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.tcc-status-pill.stable{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.tcc-status-pill.drifting{background:#fef3c7;color:#92400e;border-color:#fde68a}.tcc-status-pill.needs_contact{background:#fee2e2;color:#991b1b;border-color:#fca5a5}.tcc-snapshot-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}.tcc-snapshot-tile{border-radius:17px;background:#f8fafc;border:1px solid rgba(15,23,42,.07);padding:14px 15px;min-width:0}.tcc-snapshot-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}.tcc-snapshot-value{margin-top:8px;font-size:28px;font-weight:900;line-height:1;color:#102845;letter-spacing:-.04em}.tcc-snapshot-sub{margin-top:8px;font-size:12px;color:#64748b;line-height:1.4}.tcc-mini-bar{height:9px;border-radius:999px;background:#e5eef7;overflow:hidden;margin-top:10px}.tcc-mini-bar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#12355f 0%,#2b6dcc 100%)}.tcc-mini-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}.tcc-mini-bar span.warn{background:linear-gradient(90deg,#d97706 0%,#f59e0b 100%)}.tcc-mini-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}.tcc-issues-list{display:flex;flex-direction:column;gap:9px}.tcc-issue-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:11px 12px;border-radius:15px;background:#fbfdff;border:1px solid rgba(15,23,42,.06)}.tcc-issue-main{min-width:0;flex:1}.tcc-issue-title{font-size:13px;font-weight:900;color:#102845;line-height:1.35}.tcc-issue-meta{margin-top:4px;font-size:12px;color:#64748b;line-height:1.35}.tcc-issue-actions{display:flex;gap:7px;flex:0 0 auto;align-items:center;flex-wrap:wrap;justify-content:flex-end}.tcc-panel-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.tcc-queue{display:flex;flex-direction:column;gap:12px}.tcc-item{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px;border-radius:16px;background:#f8fbfd;border:1px solid rgba(15,23,42,.06)}.tcc-bulk-bar{display:flex;flex-direction:column;gap:10px;padding:14px;border-radius:16px;background:#f8fbfd;border:1px solid rgba(15,23,42,.06);margin-bottom:12px;min-width:0;width:100%;box-sizing:border-box;overflow:hidden}.tcc-bulk-bar-head .tcc-name{font-weight:900;color:#102845;line-height:1.25}.tcc-bulk-bar-head .tcc-sub{font-size:12px;color:#64748b;line-height:1.35}.tcc-bulk-select{width:100%;max-width:100%;min-width:0;box-sizing:border-box;min-height:40px;border-radius:11px;border:1px solid rgba(15,23,42,.12);background:#fff;color:#102845;padding:8px 10px;font:inherit;font-size:13px}.tcc-bulk-row2{display:flex;flex-wrap:wrap;align-items:center;gap:10px;width:100%;min-width:0}.tcc-bulk-row2 input.tcc-bulk-input{flex:1 1 200px;min-width:0;box-sizing:border-box;min-height:40px;border-radius:11px;border:1px solid rgba(15,23,42,.12);background:#fff;color:#102845;padding:8px 10px;font:inherit;font-size:13px}.tcc-bulk-row2 input.tcc-bulk-num{flex:0 1 104px;width:104px;min-width:72px;box-sizing:border-box}.tcc-bulk-row2 .tcc-btn{flex:0 0 auto}.tcc-bulk-hint{margin:0;font-size:12px;line-height:1.5;color:rgba(15,23,42,.62);max-width:100%}.tcc-item-left{display:flex;gap:12px;align-items:center;min-width:0}.tcc-avatar{width:44px;height:44px;border-radius:50%;background:#12355f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;flex:0 0 auto;overflow:hidden;border:3px solid #dbe7f4}.tcc-avatar.green{border-color:#16a34a}.tcc-avatar.orange{border-color:#f59e0b}.tcc-avatar.red{border-color:#dc2626}.tcc-avatar.blue{border-color:#2563eb}.tcc-avatar.purple{border-color:#7c3aed}.tcc-meta{display:flex;flex-direction:column;min-width:0}.tcc-name{font-weight:900;color:#102845;line-height:1.25}.tcc-sub{font-size:12px;color:#64748b;line-height:1.35;overflow:hidden;text-overflow:ellipsis}.tcc-severity{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;align-self:flex-start}.tcc-severity.high{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.tcc-severity.medium{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.tcc-severity.low{background:#e0f2fe;color:#075985;border:1px solid #bae6fd}.tcc-actions{display:flex;gap:8px;flex:0 0 auto}.tcc-btn,.tcc-detail-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border-radius:11px;font-size:12px;font-weight:900;border:1px solid rgba(15,23,42,.08);cursor:pointer;text-decoration:none}.tcc-btn.primary,.tcc-detail-btn.primary{background:#12355f;color:#fff;border-color:#12355f}.tcc-btn.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}.tcc-btn.secondary,.tcc-detail-btn{background:#f1f5f9;color:#334155}.tcc-btn.fix{background:#166534;color:#fff;border-color:#166534}.tcc-btn[disabled],.tcc-btn.fix[disabled]{opacity:.55;cursor:not-allowed}.tcc-empty,.tcc-loading,.tcc-timeline-loading{padding:16px;border-radius:16px;border:1px dashed rgba(15,23,42,.15);background:#fbfdff;color:#64748b;font-size:14px}.tcc-error,.tcc-timeline-error{padding:14px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-size:14px}.tcc-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px}.tcc-modal-overlay.open{display:flex}.tcc-modal-card{width:min(1120px,96vw);max-height:88vh;overflow:hidden;background:#fff;border-radius:22px;border:1px solid rgba(15,23,42,.10);box-shadow:0 24px 70px rgba(15,23,42,.35);display:flex;flex-direction:column}.tcc-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:17px 19px;border-bottom:1px solid rgba(15,23,42,.08);background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff}.tcc-modal-kicker{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.72)}.tcc-modal-title{margin-top:5px;font-size:20px;font-weight:900;line-height:1.1;color:#fff}.tcc-modal-close{border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:12px;width:34px;height:34px;font-size:24px;line-height:1;cursor:pointer}.tcc-modal-body{padding:16px 18px;overflow:auto}.tcc-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tcc-modal-section{border:1px solid rgba(15,23,42,.08);border-radius:16px;background:#fbfdff;padding:14px;min-width:0}.tcc-modal-section.full{grid-column:1/-1}.tcc-modal-section-title{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:#64748b;margin-bottom:8px}.tcc-modal-readable{font-size:13px;line-height:1.55;color:#102845;max-height:300px;overflow:auto;white-space:pre-wrap;background:#fff;border:1px solid rgba(15,23,42,.06);border-radius:12px;padding:12px}.tcc-modal-muted{font-size:12px;color:#64748b;line-height:1.45}.tcc-modal-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid rgba(15,23,42,.06);font-size:13px}.tcc-modal-row:last-child{border-bottom:0}.tcc-modal-label{color:#64748b;font-weight:800}.tcc-modal-value{color:#102845;font-weight:800;text-align:right}.tcc-debug-pre{white-space:pre-wrap;background:#0f172a;color:#e2e8f0;border-radius:16px;padding:14px;font-size:12px;line-height:1.45;overflow:auto}.tcc-debug-meta{font-size:13px;color:#475569;margin-bottom:10px;line-height:1.45}.tcc-attempt-card{border:1px solid rgba(15,23,42,.08);border-radius:16px;background:#fff;margin-bottom:12px;overflow:hidden}.tcc-attempt-head{display:flex;justify-content:space-between;gap:12px;padding:12px 14px;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.06)}.tcc-attempt-title{font-weight:900;color:#102845}.tcc-attempt-meta{font-size:12px;color:#64748b;margin-top:3px}.tcc-score-pill{display:inline-flex;align-items:center;justify-content:center;min-width:34px;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:900;border:1px solid transparent}.tcc-score-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}.tcc-score-pill.danger{background:#fee2e2;border-color:#fca5a5;color:#991b1b}.tcc-score-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}.tcc-score-pill.neutral{background:#edf2f7;border-color:#d7dee9;color:#475569}.tcc-count-mini{display:inline-flex;align-items:center;justify-content:center;min-width:34px;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:900;background:#edf4ff;color:#1d4f91;border:1px solid #d3e3ff}.tcc-count-mini.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}.tcc-count-mini.neutral{background:#edf2f7;color:#475569;border-color:#d7dee9}.tcc-chat-thread{display:flex;flex-direction:column;gap:10px;padding:10px 12px}.tcc-chat-bubble{max-width:78%;border-radius:18px;padding:11px 13px;font-size:13px;line-height:1.45;box-shadow:0 6px 14px rgba(15,23,42,.06)}.tcc-chat-bubble.ai{align-self:flex-start;background:#eef2f7;color:#111827;border:1px solid rgba(15,23,42,.06);border-bottom-left-radius:6px}.tcc-chat-bubble.student{align-self:flex-end;background:#dbeafe;color:#0f172a;border:1px solid #bfdbfe;border-bottom-right-radius:6px;cursor:pointer}.tcc-chat-score{margin-top:7px;font-size:11px;font-weight:900;opacity:.9}.tcc-chat-play{margin-top:8px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 9px;background:#fff;color:#1d4ed8;border:1px solid #93c5fd;font-size:11px;font-weight:900}.tcc-audio{margin-top:8px;width:100%}.tcc-intervention-list{display:flex;flex-direction:column;gap:10px}.tcc-intervention-item{border:1px solid rgba(15,23,42,.07);border-radius:14px;background:#fff;padding:11px}.tcc-intervention-clickable{cursor:pointer;transition:transform .08s ease,box-shadow .12s ease}.tcc-intervention-clickable:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(15,23,42,.08)}.tcc-intervention-title{font-size:13px;font-weight:900;color:#102845}.tcc-intervention-meta{margin-top:4px;font-size:12px;color:#64748b}.tcc-json-mini{margin-top:8px;white-space:pre-wrap;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:10px;font-size:11px;max-height:180px;overflow:auto}.tcc-lesson-timeline{margin-top:16px;border-radius:20px;border:1px solid rgba(15,23,42,.07);background:#fff;overflow:hidden}.tcc-lesson-timeline-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(15,23,42,.06);background:linear-gradient(180deg,#ffffff 0%,#fbfdff 100%)}.tcc-lesson-timeline-title{font-size:18px;line-height:1.1;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-lesson-timeline-sub{margin-top:5px;font-size:12px;color:#64748b;line-height:1.4}.tcc-module-stack{display:flex;flex-direction:column;gap:12px;padding:14px;background:#fbfdff}.tcc-module-card{border:1px solid rgba(15,23,42,.07);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 20px rgba(15,23,42,.04)}.tcc-module-card summary{list-style:none;cursor:pointer;padding:14px 16px;background:#fff}.tcc-module-card summary::-webkit-details-marker{display:none}.tcc-module-head{display:grid;grid-template-columns:52px minmax(0,1fr) 140px 140px;gap:12px;align-items:center}.tcc-module-badge{width:42px;height:42px;border-radius:999px;background:linear-gradient(135deg,#081c33 0%,#11345d 100%);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px}.tcc-module-label{font-size:10px;color:#5f718d;margin-bottom:4px;font-weight:900;text-transform:uppercase;letter-spacing:.13em}.tcc-module-title{font-size:19px;font-weight:900;color:#152235;line-height:1.12;letter-spacing:-.02em;word-break:break-word}.tcc-module-mini{font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.tcc-module-value{margin-top:4px;font-size:18px;font-weight:900;color:#102845;line-height:1}.tcc-module-body{border-top:1px solid rgba(15,23,42,.06);background:#fff}.tcc-instructor-lesson-wrap{overflow-x:auto}.tcc-instructor-lesson-table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:1080px}.tcc-instructor-lesson-table th{padding:11px 8px;border-bottom:1px solid rgba(15,23,42,.07);font-size:10px;text-transform:uppercase;letter-spacing:.13em;color:#60718b;font-weight:900;text-align:left;white-space:nowrap;background:#fbfdff}.tcc-instructor-lesson-table td{padding:10px 8px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:middle;text-align:left}.tcc-instructor-lesson-table tr.tcc-lesson-row{cursor:pointer;transition:background .12s ease}.tcc-instructor-lesson-table tr.tcc-lesson-row:hover td{background:#f8fbfd}.tcc-instructor-lesson-table th:first-child,.tcc-instructor-lesson-table td:first-child{padding-left:15px}.tcc-instructor-lesson-table th:last-child,.tcc-instructor-lesson-table td:last-child{padding-right:15px}.tcc-lesson-main{display:flex;gap:8px;align-items:flex-start;min-width:0}.tcc-lesson-num{flex:0 0 auto;color:#3b4f68;font-weight:900;font-size:12px;line-height:1.25;min-width:18px;text-align:right}.tcc-lesson-name{font-size:13px;font-weight:900;color:#152235;line-height:1.25;word-break:break-word}.tcc-date-stack{display:flex;flex-direction:column;gap:5px;min-width:0}.tcc-date-line{font-size:11px;color:#152235;line-height:1.2;font-weight:800;white-space:normal}.tcc-date-line span{display:inline-block;min-width:56px;color:#64748b;font-weight:900;text-transform:uppercase;font-size:9px;letter-spacing:.08em}.tcc-small-grey{font-size:10px;color:#64748b;line-height:1.25;font-weight:700}.tcc-extension-ok{font-size:10px;color:#166534;line-height:1.25;font-weight:900}.tcc-extension-warn{font-size:10px;color:#991b1b;line-height:1.25;font-weight:900}.tcc-delta{font-size:10px;line-height:1.25;font-weight:900;margin-top:4px}.tcc-delta.ok{color:#166534}.tcc-delta.danger{color:#991b1b}.tcc-delta.neutral{color:#64748b}.tcc-delta.warn{color:#92400e}.tcc-quality-wrap{min-width:0}.tcc-quality-top{font-size:12px;font-weight:900;color:#102845;line-height:1.2}.tcc-quality-bar{width:100%;height:7px;border-radius:999px;overflow:hidden;background:#e7edf4;margin-top:5px}.tcc-quality-bar span{display:block;height:7px;border-radius:999px;background:linear-gradient(90deg,#64748b 0%,#94a3b8 100%)}.tcc-quality-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}.tcc-quality-bar span.warn{background:linear-gradient(90deg,#b45309 0%,#f59e0b 100%)}.tcc-quality-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}.tcc-quality-bar span.info{background:linear-gradient(90deg,#1d4f91 0%,#3b82f6 100%)}.tcc-lesson-detail-row td{padding:0!important;background:#fbfdff!important}.tcc-lesson-detail{display:none;padding:14px 16px;border-top:1px solid rgba(15,23,42,.05);background:#fbfdff}.tcc-lesson-detail.open{display:block}.tcc-inline-detail-stack{display:flex;flex-direction:column;gap:14px}.tcc-inline-section{border:1px solid rgba(15,23,42,.08);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 18px rgba(15,23,42,.035)}.tcc-inline-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:13px 15px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);border-bottom:1px solid rgba(15,23,42,.06);color:#fff}.tcc-inline-section-title{font-size:14px;font-weight:900;color:#fff;line-height:1.15;letter-spacing:-.015em}.tcc-inline-section-sub{margin-top:4px;font-size:11px;color:rgba(255,255,255,.76);line-height:1.35;font-weight:700}.tcc-inline-section-head .tcc-detail-btn.primary{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.28);color:#fff}.tcc-inline-section-body{padding:14px 15px}.tcc-inline-loading,.tcc-inline-empty{padding:13px;border:1px dashed rgba(15,23,42,.14);border-radius:14px;background:#fff;color:#64748b;font-size:13px}.tcc-inline-error{padding:13px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#991b1b;font-size:13px}.tcc-inline-mini-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}.tcc-inline-mini-card{border-radius:13px;background:#fbfdff;border:1px solid rgba(15,23,42,.06);padding:10px}.tcc-inline-mini-label{font-size:9px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}.tcc-inline-mini-value{margin-top:5px;font-size:13px;font-weight:900;color:#102845;line-height:1.2}.tcc-inline-attempt{border:1px solid rgba(15,23,42,.07);border-radius:15px;background:#fbfdff;margin-bottom:10px;overflow:hidden}.tcc-inline-attempt-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:11px 12px;background:#fff;border-bottom:1px solid rgba(15,23,42,.06)}.tcc-inline-attempt-title{font-size:13px;font-weight:900;color:#102845;line-height:1.25}.tcc-inline-attempt-meta{margin-top:3px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}.tcc-inline-intervention{border:1px solid rgba(15,23,42,.07);border-radius:14px;background:#fbfdff;padding:11px 12px;margin-bottom:9px}.tcc-inline-intervention-title{font-size:12px;font-weight:900;color:#102845;line-height:1.3}.tcc-inline-intervention-meta{margin-top:4px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}.tcc-review-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:900;border:1px solid transparent}.tcc-review-pill.acceptable,.tcc-review-pill.ok{background:#dcfce7;color:#166534;border-color:#86efac}.tcc-review-pill.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}.tcc-review-pill.needs_revision,.tcc-review-pill.rejected,.tcc-review-pill.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}.tcc-review-pill.neutral{background:#edf2f7;color:#475569;border-color:#d7dee9}.tcc-review-score-row{display:flex;align-items:center;gap:10px}.tcc-review-score-bar{height:9px;flex:1;border-radius:999px;background:#e7edf4;overflow:hidden;min-width:80px}.tcc-review-score-bar span{display:block;height:100%;border-radius:999px}.tcc-review-score-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}.tcc-review-score-bar span.warn{background:linear-gradient(90deg,#d97706 0%,#f59e0b 100%)}.tcc-review-score-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}.tcc-review-score-value{font-size:12px;font-weight:900;color:#102845;min-width:42px;text-align:right}.tcc-summary-paper,.tcc-summary-safe-frame{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:0 8px 20px rgba(15,23,42,.045);padding:18px 20px;max-height:430px;overflow:auto;color:#233246;line-height:1.82;font-size:15px}.tcc-summary-paper.nb-content{white-space:normal}.tcc-summary-paper p,.tcc-summary-safe-frame p{margin:0 0 12px 0}.tcc-summary-paper ul,.tcc-summary-paper ol,.tcc-summary-safe-frame ul,.tcc-summary-safe-frame ol{margin:0 0 12px 22px}.tcc-ai-live-panel{margin-top:12px;border:1px solid rgba(15,23,42,.08);border-radius:18px;background:#ffffff;overflow:hidden}.tcc-ai-live-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:13px 15px;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.06)}.tcc-ai-live-title{font-size:13px;font-weight:900;color:#102845;line-height:1.2}.tcc-ai-live-sub{margin-top:4px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}.tcc-ai-live-body{padding:13px 15px}.tcc-ai-action-btn{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border-radius:10px;padding:0 11px;font-size:11px;font-weight:900;border:1px solid rgba(15,23,42,.08);background:#12355f;color:#fff;cursor:pointer;white-space:nowrap}.tcc-ai-action-btn[disabled]{opacity:.55;cursor:not-allowed}.tcc-ai-result-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.tcc-ai-result-card{border:1px solid rgba(15,23,42,.08);border-radius:15px;background:#fbfdff;padding:11px;min-width:0}.tcc-ai-result-card.ok{background:#f0fdf4;border-color:#bbf7d0}.tcc-ai-result-card.warn{background:#fffbeb;border-color:#fde68a}.tcc-ai-result-card.danger{background:#fef2f2;border-color:#fecaca}.tcc-ai-result-label{font-size:9px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}.tcc-ai-result-value{margin-top:6px;font-size:14px;font-weight:900;color:#102845;line-height:1.2}.tcc-ai-result-card.ok .tcc-ai-result-value{color:#166534}.tcc-ai-result-card.warn .tcc-ai-result-value{color:#92400e}.tcc-ai-result-card.danger .tcc-ai-result-value{color:#991b1b}.tcc-ai-take-box{margin-top:10px;border-radius:15px;background:#fbfdff;border:1px solid rgba(15,23,42,.07);padding:12px;font-size:13px;color:#334155;line-height:1.5}.tcc-ai-list-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}.tcc-ai-list-box{border-radius:15px;border:1px solid rgba(15,23,42,.07);background:#fff;padding:12px}.tcc-ai-list-title{font-size:11px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b;margin-bottom:8px}.tcc-ai-list-box ul{margin:0 0 0 18px;padding:0}.tcc-ai-list-box li{font-size:12px;color:#334155;line-height:1.45;margin-bottom:5px}.tcc-ai-error-box{padding:12px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-size:13px;line-height:1.45}.tcc-ai-loading-box{padding:12px;border-radius:14px;border:1px dashed rgba(15,23,42,.16);background:#fbfdff;color:#64748b;font-size:13px}.tcc-approval-alert{padding:12px 13px;border-radius:14px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;font-size:13px;line-height:1.45;margin-bottom:12px}.tcc-approval-action-strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid rgba(15,23,42,.08)}.tcc-decision-option{border:1px solid rgba(15,23,42,.08);border-radius:14px;background:#fff;padding:12px}.tcc-decision-title{font-size:13px;font-weight:900;color:#102845}.tcc-decision-help{margin-top:4px;font-size:12px;color:#64748b;line-height:1.4}@media (max-width:1100px){.tcc-hero-head{flex-direction:column;align-items:stretch}.tcc-toolbar{width:100%}.tcc-field{min-width:0;width:100%}.tcc-health-strip,.tcc-snapshot-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:900px){.tcc-module-head{grid-template-columns:46px 1fr}.tcc-module-mini,.tcc-module-value{display:none}.tcc-instructor-lesson-table{min-width:980px}.tcc-ai-result-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tcc-ai-list-grid{grid-template-columns:1fr}}@media (max-width:800px){.tcc-modal-grid{grid-template-columns:1fr}.tcc-modal-card{width:98vw}.tcc-modal-section.full{grid-column:auto}.tcc-inline-mini-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tcc-chat-bubble{max-width:92%}}@media (max-width:700px){.tcc-hero{padding:18px}.tcc-hero h1{font-size:25px}.tcc-health-strip,.tcc-snapshot-grid{grid-template-columns:1fr}.tcc-radar-wrap{min-height:214px;padding-left:18px;padding-right:18px;overflow-x:auto}.tcc-radar-line{left:24px;right:24px}.tcc-radar-tools{position:absolute;left:16px;right:16px;bottom:14px;justify-content:space-between}.tcc-student-select{min-width:0;width:100%}.tcc-item{align-items:flex-start;flex-direction:column}.tcc-actions{width:100%;justify-content:flex-start}.tcc-section-head{flex-direction:column}.tcc-student-head{align-items:flex-start}.tcc-student-name{font-size:21px}}@media(max-width:520px){.tcc-ai-result-grid{grid-template-columns:1fr}}
.tcc-deep-tab-row{display:flex;gap:8px;margin:0 0 0;padding:0 18px 12px;border-bottom:1px solid rgba(15,23,42,.08);flex-wrap:wrap}
.tcc-deep-tab{appearance:none;border:1px solid rgba(15,23,42,.1);background:#f8fafc;color:#475569;font:800 12px/1 system-ui,sans-serif;padding:9px 14px;border-radius:12px;cursor:pointer}
.tcc-deep-tab.active{background:#12355f;color:#fff;border-color:#12355f}
.tcc-deep-pane{min-height:120px}
.tcc-audit-feed{display:flex;flex-direction:column;gap:10px;padding:14px 16px 18px}
.tcc-audit-row{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:16px;border:1px solid rgba(15,23,42,.08);background:#fff;box-shadow:0 4px 12px rgba(15,23,42,.04)}
.tcc-audit-row-main{flex:1;min-width:0}
.tcc-audit-row-head{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin-bottom:6px}
.tcc-audit-chip{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#eef2ff;color:#1e3a8a;border:1px solid #c7d2fe}
.tcc-audit-chip.email{background:#ecfeff;color:#0e7490;border-color:#99f6e4}
.tcc-audit-chip.deadline_override{background:#fffbeb;color:#92400e;border-color:#fde68a}
.tcc-audit-chip.progression_event{background:#f5f3ff;color:#5b21b6;border-color:#ddd6fe}
.tcc-audit-ts{font-size:11px;font-weight:800;color:#64748b;white-space:nowrap}
.tcc-audit-lesson{font-size:11px;font-weight:900;color:#334155;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tcc-audit-label{font-size:13px;font-weight:900;color:#102845;line-height:1.35}
.tcc-audit-meta{margin-top:4px;font-size:12px;color:#64748b;line-height:1.4}
.tcc-audit-mail{flex:0 0 auto;border:1px solid rgba(15,23,42,.12);background:#fff;border-radius:12px;width:40px;height:40px;cursor:pointer;font-size:18px;line-height:1;display:inline-flex;align-items:center;justify-content:center;color:#0f766e;transition:transform .1s ease,box-shadow .12s ease}
.tcc-audit-mail:hover{transform:scale(1.06);box-shadow:0 6px 14px rgba(15,23,42,.12)}
.tcc-audit-row .tcc-audit-row-main.tcc-intervention-clickable{cursor:pointer}
.tcc-li-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;padding:0 2px}
.tcc-li-pane{margin-top:4px}
.tcc-audit-controls-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px 14px;margin-bottom:14px;padding:14px 16px;border-radius:16px;background:#f8fafc;border:1px solid rgba(15,23,42,.07)}
.tcc-audit-field{display:flex;flex-direction:column;gap:5px;min-width:0}
.tcc-audit-field label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#64748b}
.tcc-audit-field select,.tcc-audit-field input{font:inherit;font-size:13px;padding:7px 9px;border-radius:10px;border:1px solid rgba(15,23,42,.12);background:#fff;color:#102845}
.tcc-audit-toggles{display:flex;flex-wrap:wrap;gap:12px 18px;align-items:center;font-size:13px;font-weight:800;color:#334155}
.tcc-audit-toggles label{display:inline-flex;gap:7px;align-items:center;cursor:pointer}
.tcc-engine-details{margin-top:14px;border-radius:16px;border:1px solid rgba(15,23,42,.08);background:#fafafa;padding:0 14px 12px}
.tcc-engine-details summary{cursor:pointer;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#64748b;padding:12px 4px;list-style:none}
.tcc-engine-details summary::-webkit-details-marker{display:none}
.tcc-audit-row-highlight{outline:2px solid #1d4f89;box-shadow:0 0 0 4px rgba(29,79,137,.12)}
.tcc-lesson-click{cursor:pointer;border-radius:12px;padding:4px 6px;margin:-4px -6px;transition:background .15s;display:inline-block}
.tcc-lesson-click:hover{background:rgba(29,79,137,.08)}
.tcc-attempt-stale{border-color:#f59e0b!important;background:#fffbeb!important}
.tcc-oral-ai-panel{border-radius:16px;border:1px solid rgba(15,23,42,.08);background:#f8fafc;padding:14px;margin-bottom:14px}
.tcc-oral-ai-cols{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:10px}
.tcc-policy-alert{border-radius:12px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:800;font-size:12px;margin-bottom:10px;line-height:1.45}
.tcc-inline-json{margin-top:10px;font-size:11px}
.tcc-li-toolbar .tcc-btn.active{outline:2px solid rgba(18,53,95,.35);outline-offset:2px}
</style>

<div class="tcc-page">
    <section class="tcc-card tcc-hero">
        <div class="tcc-hero-head">
            <div>
                <div class="tcc-kicker">Instructor Workspace</div>
                <h1>Instructor Theory Control Center</h1>
                <div class="tcc-hero-sub">Live action queue, cohort radar, and risk visibility for keeping theory progression moving with minimal instructor workload and maximum traceability.</div>
            </div>
            <div class="tcc-toolbar">
                <div class="tcc-field">
                    <label for="cohortSelect">Cohort</label>
                    <select id="cohortSelect" class="tcc-select"></select>
                </div>
            </div>
        </div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Cohort Health</h2>
                <div class="tcc-section-sub">High-level operational status for the selected cohort.</div>
            </div>
            <div id="healthCount" class="tcc-count-pill">—</div>
        </div>
        <div id="healthStrip" class="tcc-health-strip"><div class="tcc-loading">Loading cohort health…</div></div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Cohort Radar</h2>
                <div class="tcc-section-sub">Position = course progress. Ring color: blue when a required action is open, red when blocked, green on track, orange when the engagement score is weak but the queue is clear. Hover an avatar for the full engagement explanation.</div>
            </div>
            <div id="radarCount" class="tcc-count-pill">—</div>
        </div>
        <div class="tcc-radar-wrap">
            <div class="tcc-radar-line"></div>
            <div class="tcc-radar-marker start">Start</div>
            <div class="tcc-radar-marker end">Complete</div>
            <div id="radarAvatars"></div>
            <div class="tcc-radar-tools">
                <label for="studentSelect">Student</label>
                <select id="studentSelect" class="tcc-student-select"><option value="0">Select student…</option></select>
            </div>
        </div>
        <div class="tcc-radar-legend">
            <span class="tcc-legend-pill"><span class="tcc-dot green"></span>On track</span>
            <span class="tcc-legend-pill"><span class="tcc-dot orange"></span>At risk (engagement)</span>
            <span class="tcc-legend-pill"><span class="tcc-dot red"></span>Blocked</span>
            <span class="tcc-legend-pill"><span class="tcc-dot blue"></span>Action pending</span>
            <span class="tcc-legend-pill"><span class="tcc-dot purple"></span>System watch</span>
        </div>
    </section>

    <section id="tccStudentSnapshotSection" class="tcc-card tcc-section" style="display:none">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Student Deep Dive</h2>
                <div class="tcc-section-sub">Snapshot of the selected student's progress, friction points, blockers, and comparative indicators.</div>
            </div>
            <div id="studentPanelCount" class="tcc-count-pill">—</div>
        </div>
        <div id="studentPanel"></div>
    </section>

    <section id="tccLessonInterventionsSection" class="tcc-card tcc-section" style="display:none">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Lesson & Interventions</h2>
                <div class="tcc-section-sub">Lessons by module, chronological interventions, and related mail.</div>
            </div>
        </div>
        <div id="lessonInterventionsInner"></div>
    </section>

    <section id="needsMyActionSection" class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Needs My Action</h2>
                <div class="tcc-section-sub">Instructor-facing queue of open required actions. This remains last so the page opens with cohort context first.</div>
            </div>
            <div id="queueCount" class="tcc-count-pill">—</div>
        </div>
        <div id="bulkActionBar" class="tcc-bulk-bar">
            <div class="tcc-bulk-bar-head">
                <div class="tcc-name">Bulk Intervention</div>
                <div class="tcc-sub">Select blockers below and run a cohort-safe action.</div>
            </div>
            <select id="bulkActionCode" class="tcc-bulk-select" title="Bulk action type">
                <option value="">Choose action…</option>
                <option value="approve_deadline_reason_submission" title="Deadline reason flow: approve explanation and optional extension">Deadline reason — approve explanation (+Days optional)</option>
                <option value="approve_additional_attempts" title="Instructor approval queue, including missed final deadline">Instructor approval — grant attempts (notes +Attempts required)</option>
            </select>
            <div class="tcc-bulk-row2">
                <input id="bulkDecisionNotes" type="text" class="tcc-bulk-input" placeholder="Decision notes (required for instructor approval bulk)" autocomplete="off">
                <input id="bulkGrantedAttempts" type="number" min="0" max="5" class="tcc-bulk-input tcc-bulk-num" placeholder="+Attempts" title="Minimum 1 for instructor-approval bulk">
                <input id="bulkExtensionDays" type="number" min="0" max="10" class="tcc-bulk-input tcc-bulk-num" placeholder="+Days" title="Optional deadline extension (deadline-reason path)">
                <button id="bulkPreviewBtn" class="tcc-btn secondary" type="button">Preview</button>
                <button id="bulkExecuteBtn" class="tcc-btn primary" type="button">Execute</button>
            </div>
            <p id="bulkActionHint" class="tcc-bulk-hint">Choose a bulk action, select queue rows, then Preview.</p>
        </div>
        <div id="bulkFamilyControls" class="tcc-panel-actions" style="margin-top:0;margin-bottom:12px;"></div>
        <div id="actionQueue" class="tcc-queue"><div class="tcc-loading">Loading action queue…</div></div>
    </section>
</div>

<div id="tccModalOverlay" class="tcc-modal-overlay" aria-hidden="true">
    <div class="tcc-modal-card">
        <div class="tcc-modal-head">
            <div>
                <div class="tcc-modal-kicker">Instructor Diagnostic</div>
                <div id="tccModalTitle" class="tcc-modal-title">Debug Report</div>
            </div>
            <button type="button" class="tcc-modal-close" onclick="closeTccModal()">×</button>
        </div>
        <div id="tccModalBody" class="tcc-modal-body"></div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var cohortId = 0;
    var selectedStudentId = 0;
    var studentAuditTimelineCache = null;
    var studentAuditTimelineCacheStudentId = 0;
    var pendingAuditHighlightLessonId = 0;
    var cohortStudents = [];
    var queueItemsById = {};
    var selectedQueueIds = {};
    var queueFamilyMemberIds = {deadline_related: [], progress_test_failure_related: [], other: []};

    function setNeedsMyActionSectionVisible(visible) {
        var el = document.getElementById('needsMyActionSection');
        if (el) el.style.display = visible ? '' : 'none';
    }

    function setStudentDeepDiveSectionVisible(visible) {
        var el = document.getElementById('tccStudentSnapshotSection');
        if (el) el.style.display = visible ? '' : 'none';
    }

    function setLessonInterventionsSectionVisible(visible) {
        var el = document.getElementById('tccLessonInterventionsSection');
        if (el) el.style.display = visible ? '' : 'none';
    }

    function clearStudentSelectionUi() {
        selectedStudentId = 0;
        studentAuditTimelineCache = null;
        studentAuditTimelineCacheStudentId = 0;
        selectStudentInUi(0);
        setNeedsMyActionSectionVisible(true);
        setStudentDeepDiveSectionVisible(false);
        setLessonInterventionsSectionVisible(false);
        var panel = document.getElementById('studentPanel');
        if (panel) {
            panel.className = 'tcc-student-placeholder';
            panel.innerHTML = 'Tap a student avatar in the Cohort Radar, choose a student from the selector, or tap a student in the Action Queue to open the student deep dive here.';
        }
        var liInner = document.getElementById('lessonInterventionsInner');
        if (liInner) liInner.innerHTML = '';
        document.getElementById('studentPanelCount').textContent = 'Select student';
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
        });
    }

    function api(action, params) {
        params = params || {};
        params.action = action;
        return fetch('/instructor/api/theory_control_center_api.php?' + new URLSearchParams(params), {credentials: 'same-origin'}).then(function (r) { return r.json(); });
    }

    function repairApi(payload) {
        return fetch('/instructor/api/theory_control_center_repair_execute.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }

    function openTccModal(title, bodyHtml, kicker) {
        document.querySelector('#tccModalOverlay .tcc-modal-kicker').textContent = kicker || 'Instructor Diagnostic';
        document.getElementById('tccModalTitle').textContent = title || 'Diagnostic';
        document.getElementById('tccModalBody').innerHTML = bodyHtml || '';
        var o = document.getElementById('tccModalOverlay');
        o.classList.add('open');
        o.setAttribute('aria-hidden', 'false');
    }

    function closeTccModal() {
        var o = document.getElementById('tccModalOverlay');
        o.classList.remove('open');
        o.setAttribute('aria-hidden', 'true');
    }

    function showError(id, msg) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = '<div class="tcc-error">' + escapeHtml(msg) + '</div>';
    }

    function clampPct(v) {
        v = parseFloat(v);
        if (isNaN(v)) v = 0;
        return Math.max(0, Math.min(100, Math.round(v)));
    }

    function firstName(name) {
        return String(name || 'Student').trim().split(/\s+/)[0] || 'Student';
    }

    function photoPath(o) {
        var path = String((o && (o.photo_path || o.avatar_url || o.photoPath || o.image_url)) || '').trim();
        if (path === '') return '';
        if (path.indexOf('http://') === 0 || path.indexOf('https://') === 0 || path.indexOf('/') === 0) return path;
        return '/' + path.replace(/^\/+/, '');
    }

    function avatarHtml(o, cls) {
        var path = photoPath(o);
        var initials = escapeHtml((o && o.avatar_initials) || 'S');
        if (path !== '') return '<div class="' + cls + '"><img src="' + escapeHtml(path) + '" alt="' + escapeHtml((o && o.name) || 'Student') + '"></div>';
        return '<div class="' + cls + '">' + initials + '</div>';
    }

    function radarColor(s) {
        s = s || {};
        if (s.state === 'blocked') return 'red';
        if (s.pending_action_count && parseInt(s.pending_action_count, 10) > 0) return 'blue';
        if (s.state === 'at_risk') return 'orange';
        return 'green';
    }

    function officialFlowHref(item) {
        item = item || {};
        if (item.official_flow_url) return String(item.official_flow_url);
        if (item.action_url) return String(item.action_url);
        if (item.token && String(item.type || item.action_type || '') === 'instructor_approval') return '/instructor/instructor_approval.php?token=' + encodeURIComponent(item.token);
        return '';
    }

    function cohortTimeZone() {
        return window.tccCohortTimezone || 'UTC';
    }

    function parseUtcDate(v) {
        if (!v) return null;
        var raw = String(v).trim();
        if (raw === '') return null;
        var iso = raw.indexOf('T') >= 0 ? raw : (raw.replace(' ', 'T') + 'Z');
        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    function partsInCohortTime(v) {
        var d = parseUtcDate(v);
        if (!d) return null;
        try {
            var fmt = new Intl.DateTimeFormat('en-US', {timeZone: cohortTimeZone(), weekday:'short', month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit', hour12:false});
            var parts = {};
            fmt.formatToParts(d).forEach(function (p) { parts[p.type] = p.value; });
            return parts;
        } catch (e) {
            return null;
        }
    }

    function niceDate(v) {
        var p = partsInCohortTime(v);
        if (!p) return v ? String(v).slice(0, 16) : '—';
        return p.weekday + ' ' + p.month + ' ' + p.day + ', ' + p.year;
    }

    function niceDateTime(v) {
        var p = partsInCohortTime(v);
        if (!p) return v ? String(v).slice(0, 16) : '—';
        return p.weekday + ' ' + p.month + ' ' + p.day + ', ' + p.year + ' ' + p.hour + ':' + p.minute;
    }

    function niceTime(v) {
        var p = partsInCohortTime(v);
        if (!p) return '—';
        return p.hour + ':' + p.minute;
    }

    function prettyStatus(s) {
        s = String(s || '').replace(/_/g, ' ');
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Not started';
    }

    function formatScoreValue(value, max) {
        if (value === null || value === undefined || value === '') return '—';
        if (max !== undefined && max !== null && max !== '') return String(value) + ' / ' + String(max);
        return String(value);
    }

    function modalStatusRows(rows) {
        var html = '';
        rows.forEach(function (row) {
            html += '<div class="tcc-modal-row"><div class="tcc-modal-label">' + escapeHtml(row[0]) + '</div><div class="tcc-modal-value">' + escapeHtml(row[1]) + '</div></div>';
        });
        return html;
    }

    function jsArg(value) {
        return '\'' + String(value === null || value === undefined ? '' : value)
            .replace(/\\/g, '\\\\')
            .replace(/'/g, "\\'")
            .replace(/\r/g, '\\r')
            .replace(/\n/g, '\\n')
            .replace(/</g, '\\x3C')
            .replace(/>/g, '\\x3E') + '\'';
    }

    function sanitizeSummaryHtml(html) {
        html = String(html || '');
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        var allowedTags = {P:1,BR:1,UL:1,OL:1,LI:1,STRONG:1,B:1,EM:1,I:1,U:1,H1:1,H2:1,H3:1,H4:1,BLOCKQUOTE:1,SPAN:1,DIV:1,MARK:1};
        var walker = document.createTreeWalker(tpl.content, NodeFilter.SHOW_ELEMENT, null);
        var remove = [];
        while (walker.nextNode()) {
            var el = walker.currentNode;
            if (!allowedTags[el.tagName]) {
                remove.push(el);
                continue;
            }
            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                if (a.name.toLowerCase() !== 'class') el.removeAttribute(a.name);
            });
        }
        remove.forEach(function (el) {
            var text = document.createTextNode(el.textContent || '');
            if (el.parentNode) el.parentNode.replaceChild(text, el);
        });
        return tpl.innerHTML;
    }

    function rawSummaryHtml(s) {
        var html = String((s && (s.summary_html || s.summaryHtml)) || '').trim();
        if (html !== '') return sanitizeSummaryHtml(html);
        var plain = String((s && (s.summary_plain || s.summary_text)) || '').trim();
        return plain !== '' ? escapeHtml(plain).replace(/\n/g, '<br>') : 'No student summary text found.';
    }

    function reviewStatusPill(status) {
        var clean = String(status || 'neutral').toLowerCase();
        return '<span class="tcc-review-pill ' + escapeHtml(clean) + '">' + escapeHtml(prettyStatus(clean)) + '</span>';
    }

    function reviewScoreBar(score) {
        var n = (score !== null && score !== undefined && score !== '') ? clampPct(score) : 0;
        var cls = n >= 85 ? 'ok' : (n >= 70 ? 'warn' : 'danger');
        return '<div class="tcc-review-score-row"><div class="tcc-review-score-bar"><span class="' + cls + '" style="width:' + n + '%;"></span></div><div class="tcc-review-score-value">' + (score !== null && score !== undefined && score !== '' ? escapeHtml(score) + '%' : '—') + '</div></div>';
    }

    function aiSignalClass(value) {
        var s = String(value || '').toLowerCase();
        if (s.indexOf('high') >= 0 || s.indexOf('likely') >= 0 || s.indexOf('weak') >= 0 || s.indexOf('poor') >= 0) return 'danger';
        if (s.indexOf('medium') >= 0 || s.indexOf('possible') >= 0 || s.indexOf('developing') >= 0 || s.indexOf('adequate') >= 0) return 'warn';
        if (s.indexOf('low') >= 0 || s.indexOf('unlikely') >= 0 || s.indexOf('strong') >= 0 || s.indexOf('excellent') >= 0) return 'ok';
        return '';
    }

    function aiList(items) {
        items = Array.isArray(items) ? items : [];
        if (!items.length) return '<div class="tcc-modal-muted">No items generated yet.</div>';
        var html = '<ul>';
        items.slice(0, 6).forEach(function (item) { html += '<li>' + escapeHtml(item) + '</li>'; });
        return html + '</ul>';
    }

    function renderAiAnalysisObject(ai) {
        ai = ai || {};
        var copy = ai.copy_paste_likelihood || 'Not generated';
        var tool = ai.ai_tool_likelihood || 'Not generated';
        var sim = ai.highest_similarity || 'Not generated';
        var simStudent = ai.highest_similarity_student || '—';
        var simPct = (ai.highest_similarity_pct !== undefined && ai.highest_similarity_pct !== null) ? String(ai.highest_similarity_pct) + '%' : '—';
        var understandingLabel = ai.deep_understanding_label || ai.deep_understanding || ai.understanding || 'Not generated';
        var understandingScore = (ai.deep_understanding_score !== undefined && ai.deep_understanding_score !== null && String(ai.deep_understanding_score) !== '') ? String(ai.deep_understanding_score) + '%' : '';
        var html = '<div class="tcc-ai-result-grid">';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(copy) + '"><div class="tcc-ai-result-label">Copy/Paste</div><div class="tcc-ai-result-value">' + escapeHtml(copy) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(tool) + '"><div class="tcc-ai-result-label">AI Tool Use</div><div class="tcc-ai-result-value">' + escapeHtml(tool) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(sim) + '"><div class="tcc-ai-result-label">Similarity</div><div class="tcc-ai-result-value">' + escapeHtml(sim) + ' · ' + escapeHtml(simPct) + '</div><div class="tcc-modal-muted" style="margin-top:5px;">' + escapeHtml(simStudent) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(understandingLabel) + '"><div class="tcc-ai-result-label">Understanding</div><div class="tcc-ai-result-value">' + escapeHtml(understandingLabel) + (understandingScore ? (' · ' + escapeHtml(understandingScore)) : '') + '</div></div>';
        html += '</div>';
        html += '<div class="tcc-ai-take-box"><strong>Instructor quick take:</strong><br>' + escapeHtml(ai.instructor_quick_take || ai.quality_feedback || 'No AI analysis generated yet.') + '</div>';
        html += '<div class="tcc-ai-list-grid">';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">STRONG POINTS</div>' + aiList(ai.substantially_good) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">WEAK POINTS</div>' + aiList(ai.substantially_weak) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">SUGGESTIONS</div>' + aiList(ai.improvement_suggestions || ai.suggestions) + '</div>';
        html += '</div>';
        if (ai.student_safe_feedback) html += '<div class="tcc-ai-take-box"><strong>Student-safe feedback:</strong><br>' + escapeHtml(ai.student_safe_feedback) + '</div>';
        return html;
    }

    function renderAiInterpretation(ai, studentId, lessonId) {
        ai = ai || {};
        var hasGenerated = (ai.analysis_status === 'generated' || (ai.copy_paste_likelihood && String(ai.copy_paste_likelihood).indexOf('Not generated') === -1) || (ai.deep_understanding_label && String(ai.deep_understanding_label).indexOf('Not generated') === -1 && String(ai.deep_understanding_label).indexOf('Not evaluated') === -1));
        var panelId = 'aiPanel_' + parseInt(studentId || 0, 10) + '_' + parseInt(lessonId || 0, 10);
        var btnId = 'aiBtn_' + parseInt(studentId || 0, 10) + '_' + parseInt(lessonId || 0, 10);
        var body = hasGenerated ? renderAiAnalysisObject(ai) : '<div class="tcc-ai-loading-box">No AI analysis generated yet. Click “Generate AI Analysis” to create an instructor advisory review for this summary.</div>';
        return '<div id="' + panelId + '" class="tcc-ai-live-panel"><div class="tcc-ai-live-head"><div><div class="tcc-ai-live-title">AI Summary Analysis</div><div class="tcc-ai-live-sub">Advisory only. Does not change progression status or canonical records.</div></div><button id="' + btnId + '" type="button" class="tcc-ai-action-btn" data-student-id="' + parseInt(studentId || 0, 10) + '" data-lesson-id="' + parseInt(lessonId || 0, 10) + '" data-panel-id="' + escapeHtml(panelId) + '" data-btn-id="' + escapeHtml(btnId) + '" onclick="window.generateAiSummaryAnalysisFromButton(this)">Generate AI Analysis</button></div><div class="tcc-ai-live-body">' + body + '</div></div>';
    }

    function generateAiSummaryAnalysis(studentId, lessonId, panelId, btnId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        var panel = document.getElementById(panelId);
        var btn = document.getElementById(btnId);
        if (!panel || !studentId || !lessonId || !cohortId) {
            openTccModal('AI Summary Analysis', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        var body = panel.querySelector('.tcc-ai-live-body');
        if (btn) { btn.disabled = true; btn.textContent = 'Analyzing…'; }
        if (body) body.innerHTML = '<div class="tcc-ai-loading-box">Generating AI analysis. This is advisory only and will not change student progression state…</div>';
        api('ai_summary_analysis', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId, force: 1}).then(function (resp) {
            if (!resp.ok) {
                if (body) body.innerHTML = '<div class="tcc-ai-error-box">' + escapeHtml(resp.message || resp.error || 'AI analysis failed.') + '</div>';
                return;
            }
            if (body) body.innerHTML = renderAiAnalysisObject(resp.analysis || {});
        }).catch(function () {
            if (body) body.innerHTML = '<div class="tcc-ai-error-box">Unable to generate AI analysis.</div>';
        }).finally(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Regenerate AI Analysis'; }
        });
    }

    function generateAiSummaryAnalysisFromButton(btn) {
        generateAiSummaryAnalysis(parseInt(btn.getAttribute('data-student-id') || '0', 10), parseInt(btn.getAttribute('data-lesson-id') || '0', 10), btn.getAttribute('data-panel-id') || '', btn.getAttribute('data-btn-id') || '');
    }

    function openSummaryLargeModal(title, htmlContent) {
        openTccModal(title || 'Lesson Summary', '<div class="tcc-summary-paper nb-content" style="max-height:70vh;">' + htmlContent + '</div>');
    }

    function openAnswerAudioModal(question, transcript, audioUrl, score) {
        var body = '<div class="tcc-modal-muted" style="margin-bottom:10px;">' + escapeHtml(question || 'Progress test answer') + '</div>';
        if (audioUrl) body += '<audio class="tcc-audio" controls preload="none" src="' + escapeHtml(audioUrl) + '"></audio>';
        else body += '<div class="tcc-empty">No audio file is attached to this answer.</div>';
        body += '<div class="tcc-modal-section full" style="margin-top:12px;"><div class="tcc-modal-section-title">Student Answer Transcript</div><div class="tcc-modal-readable">' + escapeHtml(transcript || '—') + '</div><div class="tcc-modal-muted" style="margin-top:8px;">Score: ' + escapeHtml(score || '—') + '</div></div>';
        openTccModal('Answer Audio + Transcript', body);
    }

    function openRequiredActionDetailModal(item) {
        item = item || {};
        var title = item.title || prettyStatus(item.action_type || 'Required action');
        var notes = String(item.decision_notes || item.instructor_notes || item.review_notes || '').trim();
        var html = '<div class="tcc-modal-grid">';
        html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Action overview</div>' + modalStatusRows([
            ['Action type', prettyStatus(item.action_type || '—')],
            ['Status', prettyStatus(item.status || '—')],
            ['Title', item.title || '—'],
            ['Created', niceDateTime(item.created_at || '')],
            ['Opened', niceDateTime(item.opened_at || '')],
            ['Completed', niceDateTime(item.completed_at || '')],
            ['Approved', niceDateTime(item.approved_at || '')],
            ['Extra attempts granted', String(item.granted_extra_attempts !== undefined && item.granted_extra_attempts !== null ? item.granted_extra_attempts : '—')],
            ['Progress test id', String(item.progress_test_id || '—')],
            ['Required action id', String(item.id || '—')]
        ]) + '</div>';
        if (String(item.instructions_text || item.instructions_html || '').trim() !== '') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Instructions to student</div><div class="tcc-modal-readable">' + escapeHtml(String(item.instructions_text || '').trim() || 'See HTML instructions in records.') + '</div></div>';
        }
        var sr = String(item.student_response_text || '').trim();
        if (sr !== '') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Student response</div><div class="tcc-modal-readable">' + escapeHtml(sr) + '</div></div>';
        }
        if (notes !== '') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Instructor / decision notes</div><div class="tcc-modal-readable">' + escapeHtml(notes) + '</div></div>';
        }
        html += '<details class="tcc-inline-json"><summary style="cursor:pointer;font-weight:800;color:#64748b;">Raw JSON (troubleshooting)</summary><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(item, null, 2)) + '</pre></details>';
        html += '</div>';
        openTccModal(title, html, 'Required intervention');
    }

    function openDeadlineOverrideDetailModal(item) {
        item = item || {};
        var title = 'Deadline change · ' + (item.override_type || 'override');
        var seq = parseInt(item.extension_sequence || 0, 10) || 0;
        var policyAlert = '';
        if (seq >= 3) {
            policyAlert = '<div class="tcc-policy-alert">Extension #' + seq + ': From the 3rd extension onward this pathway normally requires explicit instructor attention (policy: up to two automated / AI-reviewed reason-based extensions; further moves are escalation).</div>';
        }
        var grantedBy = item.granted_by_user_id ? ('User #' + String(item.granted_by_user_id)) : 'System / automation';
        var html = '<div class="tcc-modal-grid">' + policyAlert;
        html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Deadlines</div>' + modalStatusRows([
            ['Original / base deadline (UTC)', niceDateTime(item.base_deadline_utc || '')],
            ['New effective deadline (UTC)', niceDateTime(item.new_deadline_utc || '')],
            ['Granted at', niceDateTime(item.granted_at || item.created_at || '')],
            ['Extension # (this lesson)', seq ? String(seq) : '—'],
            ['Approval source', item.approval_source || '—'],
            ['Reason code', item.granted_reason_code || '—'],
            ['Granted by', grantedBy]
        ]) + '</div>';
        var gt = String(item.granted_reason_text || '').trim();
        if (gt !== '') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Reason text</div><div class="tcc-modal-readable">' + escapeHtml(gt) + '</div></div>';
        }
        html += '<details class="tcc-inline-json"><summary style="cursor:pointer;font-weight:800;color:#64748b;">Raw JSON (troubleshooting)</summary><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(item, null, 2)) + '</pre></details>';
        html += '</div>';
        openTccModal(title, html, 'Deadline change');
    }

    function openProgressionEventDetailModal(item) {
        item = item || {};
        var title = item.event_code || item.event_type || 'Progression event';
        var html = '<div class="tcc-modal-grid"><div class="tcc-modal-section full"><div class="tcc-modal-section-title">Event</div>' + modalStatusRows([
            ['Code', item.event_code || '—'],
            ['Type', item.event_type || '—'],
            ['Status', item.event_status || '—'],
            ['Time', niceDateTime(item.event_time || item.created_at || '')],
            ['Lesson id', String(item.lesson_id || '—')]
        ]) + '</div>';
        if (String(item.legal_note || '').trim() !== '') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Note</div><div class="tcc-modal-readable">' + escapeHtml(item.legal_note) + '</div></div>';
        }
        html += '<details class="tcc-inline-json"><summary style="cursor:pointer;font-weight:800;color:#64748b;">Raw JSON (troubleshooting)</summary><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(item, null, 2)) + '</pre></details></div>';
        openTccModal(title, html, 'Engine / progression event');
    }

    function openInterventionDetailModal(item) {
        item = item || {};
        if (item.email_type || (item.recipients_to !== undefined && item.subject !== undefined)) {
            openEmailBodyModal(item);
            return;
        }
        if (String(item.action_type || '') === 'deadline_reason_submission') {
            openDeadlineReasonModal(item);
            return;
        }
        if (item.base_deadline_utc !== undefined && item.new_deadline_utc !== undefined) {
            openDeadlineOverrideDetailModal(item);
            return;
        }
        if (item.event_code !== undefined || (item.event_type !== undefined && item.event_time !== undefined)) {
            openProgressionEventDetailModal(item);
            return;
        }
        if (item.action_type !== undefined && item.cohort_id !== undefined) {
            openRequiredActionDetailModal(item);
            return;
        }
        var title = item.title || item.email_type || item.event_type || item.override_type || item.action_type || ('Record #' + (item.id || ''));
        openTccModal('Intervention Detail', '<div class="tcc-debug-meta">' + escapeHtml(title) + '</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(item, null, 2)) + '</pre>');
    }

    function openDeadlineReasonModal(item) {
        item = item || {};
        var title = item.title || 'Deadline reason submission';
        var studentReason = String(item.student_response_text || '').trim();
        if (studentReason === '') {
            studentReason = 'No student reason text recorded.';
        }
        var instructions = String(item.instructions_text || '').trim();
        var systemTs = niceDateTime(item.created_at || item.opened_at || '');
        var studentTs = niceDateTime(item.completed_at || item.updated_at || item.created_at || '');
        var html = '<div class="tcc-modal-grid">';
        html += '<div class="tcc-modal-section"><div class="tcc-modal-section-title">Submission Details</div>' + modalStatusRows([
            ['Status', prettyStatus(item.status || '—')],
            ['Submitted', studentTs],
            ['Opened', niceDateTime(item.opened_at || '')]
        ]) + '</div>';
        html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Conversation</div><div class="tcc-chat-thread">';
        if (instructions !== '') {
            html += '<div class="tcc-chat-bubble ai"><strong>System</strong><div style="margin-top:4px;">' + escapeHtml(instructions) + '</div><div class="tcc-chat-score">Sent: ' + escapeHtml(systemTs) + '</div></div>';
        }
        html += '<div class="tcc-chat-bubble student"><strong>Student</strong><div style="margin-top:4px;">' + escapeHtml(studentReason) + '</div><div class="tcc-chat-score">Sent: ' + escapeHtml(studentTs) + '</div></div>';
        html += '</div></div>';
        html += '</div>';
        openTccModal(title, html, 'Student Reason Submission');
    }

    function openEmailBodyModal(item) {
        item = item || {};
        var title = item.subject || item.title || 'Progression Email';
        var sentAt = item.sent_timestamp || item.sent_at || item.created_at || '';
        var recipient = item.recipient_display || item.recipients_to_display || item.recipient_label || '—';
        var deliveryLine = item.delivery_label || '';
        if (!deliveryLine) {
            var st = String(item.sent_status || item.delivery_status || '').toLowerCase();
            deliveryLine = item.delivery_success === true || st === 'sent' ? 'Sent successfully' : (st === 'failed' ? 'Send failed' : prettyStatus(item.delivery_status || item.sent_status || 'unknown'));
        }
        var body = item.readable_body || item.body_text || 'No rendered email body available.';
        var html = '<div class="tcc-modal-grid">';
        html += '<div class="tcc-modal-section"><div class="tcc-modal-section-title">Delivery</div>' + modalStatusRows([
            ['Sent at', niceDateTime(sentAt)],
            ['Status', deliveryLine],
            ['Recipient', recipient],
            ['Email type', item.email_type || '—']
        ]) + '</div>';
        html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Rendered Content</div><div class="tcc-modal-readable">' + escapeHtml(body) + '</div></div>';
        html += '</div>';
        openTccModal(title, html, 'Readable Email');
    }

    function openDebugReport(studentId, lessonId, issueType) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Debug Report', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        openTccModal('Debug Report', '<div class="tcc-loading">Generating diagnostic report…</div>');
        api('debug_report', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId, issue_type: issueType || 'manual_check'}).then(function (data) {
            openTccModal('Debug Report', '<div class="tcc-debug-meta">Copy this JSON into Jake/Steven if this blocker represents an unexpected software state.</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>');
        }).catch(function () {
            openTccModal('Debug Report', '<div class="tcc-error">Unable to generate diagnostic report.</div>');
        });
    }

    function openSystemWatchForStudent(studentId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        if (!cohortId || !studentId) {
            openTccModal('System Watch', '<div class="tcc-error">Missing cohort or student id.</div>');
            return;
        }
        openTccModal('System Watch', '<div class="tcc-loading">Loading system watch…</div>');
        api('system_watch', {cohort_id: cohortId, student_id: studentId}).then(function (data) {
            openTccModal('System Watch', '<pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>');
        }).catch(function () {
            openTccModal('System Watch', '<div class="tcc-error">Unable to load system watch.</div>');
        });
    }

    function tccAttemptDuration(started, completed) {
        try {
            var a = new Date(String(started || '').replace(' ', 'T') + 'Z').getTime();
            var b = new Date(String(completed || '').replace(' ', 'T') + 'Z').getTime();
            if (isNaN(a) || isNaN(b) || b <= a) return '—';
            var sec = Math.round((b - a) / 1000);
            var m = Math.floor(sec / 60);
            var s = sec % 60;
            return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        } catch (e) {
            return '—';
        }
    }

    function tccToggleInlineAudio(btn) {
        if (!btn) return;
        var wrap = btn.parentNode;
        if (!wrap) return;
        var a = wrap.querySelector('audio');
        if (!a) return;
        if (a.paused) {
            a.play();
            btn.textContent = 'Pause audio';
        } else {
            a.pause();
            btn.textContent = 'Play audio';
        }
    }

    function renderAttemptItems(items) {
        if (!items || !items.length) return '<div class="tcc-modal-muted">No answer-level items found for this attempt.</div>';
        var html = '<div class="tcc-chat-thread">';
        items.forEach(function (item, idx) {
            var score = formatScoreValue(item.score_points, item.max_points);
            var q = item.prompt || item.question_text || 'Question';
            var transcript = item.transcript_text || item.answer_text || item.student_answer || '—';
            var audio = item.audio_url || item.audio_path || item.answer_audio_url || item.recording_url || item.media_url || '';
            html += '<div class="tcc-chat-bubble ai"><strong>Question ' + (idx + 1) + ':</strong> ' + escapeHtml(q) + '</div>';
            html += '<div class="tcc-chat-bubble student">';
            if (audio) {
                html += '<div class="tcc-answer-audio-wrap" style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
                html += '<button type="button" class="tcc-btn secondary" onclick="event.stopPropagation();tccToggleInlineAudio(this)">Play audio</button>';
                html += '<audio preload="none" src="' + escapeHtml(audio) + '"></audio></div>';
            }
            html += escapeHtml(transcript) + '<div class="tcc-chat-score">Score: ' + escapeHtml(score) + '</div></div>';
        });
        html += '</div>';
        return html;
    }

    function renderOralIntegrityAnalysis(ai) {
        ai = ai || {};
        var sum = String(ai.instructor_summary || '').trim();
        var html = '<div class="tcc-modal-muted" style="line-height:1.55;">' + escapeHtml(sum || '—') + '</div>';
        html += '<div class="tcc-ai-result-grid" style="margin-top:12px;">';
        html += '<div class="tcc-ai-result-card"><div class="tcc-ai-result-label">Natural speech</div><div class="tcc-ai-result-value">' + escapeHtml(ai.natural_speech_likelihood || '—') + '</div></div>';
        html += '<div class="tcc-ai-result-card"><div class="tcc-ai-result-label">Script reading</div><div class="tcc-ai-result-value">' + escapeHtml(ai.script_reading_likelihood || '—') + '</div></div>';
        html += '<div class="tcc-ai-result-card"><div class="tcc-ai-result-label">Other voices / coaching</div><div class="tcc-ai-result-value">' + escapeHtml(ai.multiple_voices_or_coaching_likelihood || '—') + '</div></div>';
        html += '<div class="tcc-ai-result-card"><div class="tcc-ai-result-label">Overall integrity risk</div><div class="tcc-ai-result-value">' + escapeHtml(ai.overall_integrity_risk || '—') + '</div></div>';
        html += '</div>';
        html += '<div class="tcc-oral-ai-cols">';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">STRONG POINTS</div>' + aiList(ai.strong_points || []) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">WEAK POINTS</div>' + aiList(ai.weak_points || []) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">SUGGESTIONS</div>' + aiList(ai.suggestions || []) + '</div>';
        html += '</div>';
        if (ai.official_references && ai.official_references.length) {
            html += '<div style="margin-top:10px;font-size:12px;color:#475569;"><strong>References:</strong> ' + escapeHtml(ai.official_references.join(' · ')) + '</div>';
        }
        if (ai.evidence_notes && ai.evidence_notes.length) {
            html += '<div class="tcc-modal-section full" style="margin-top:10px;"><div class="tcc-modal-section-title">Evidence notes</div>' + aiList(ai.evidence_notes) + '</div>';
        }
        return html;
    }

    function renderAttemptCards(attempts) {
        var html = '';
        if (!attempts || !attempts.length) return '<div class="tcc-empty">No progress test attempts found.</div>';
        var list = attempts.slice().sort(function (a, b) {
            return (parseInt(a.attempt, 10) || 0) - (parseInt(b.attempt, 10) || 0);
        });
        list.forEach(function (a) {
            var score = a.score_pct !== null && a.score_pct !== undefined ? a.score_pct + '%' : '—';
            var passFail = parseInt(a.pass_gate_met, 10) === 1 ? 'PASS' : 'FAIL';
            var stale = !!a.is_stale_attempt;
            var dur = tccAttemptDuration(a.started_at, a.completed_at);
            var cardCls = 'tcc-attempt-card' + (stale ? ' tcc-attempt-stale' : '');
            html += '<div class="' + cardCls + '"><div class="tcc-attempt-head"><div><div class="tcc-attempt-title">Attempt ' + escapeHtml(a.attempt || '—') + ' · ' + escapeHtml(passFail) + ' · ' + escapeHtml(a.formal_result_code || a.status || '') + '</div>';
            html += '<div class="tcc-attempt-meta">Started: ' + escapeHtml(niceDateTime(a.started_at)) + ' · Completed: ' + escapeHtml(niceDateTime(a.completed_at)) + ' (Duration: ' + escapeHtml(dur) + ')</div>';
            html += '</div><span class="tcc-score-pill ' + (parseInt(a.pass_gate_met, 10) === 1 ? 'ok' : 'danger') + '">' + escapeHtml(score) + '</span></div>';
            html += '<details open class="tcc-inline-json"><summary style="cursor:pointer;font-weight:800;color:#64748b;">Raw attempt JSON</summary><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(a, null, 2)) + '</pre></details>';
            html += renderAttemptItems(a.items || []) + '</div>';
        });
        return html;
    }

    function buildProgressTestModalHtml(d, oralAnalysis, aiLoading) {
        var lesson = d.lesson || {};
        var sub = escapeHtml((lesson.course_title || 'Module') + ' · ' + (lesson.lesson_title || 'Lesson'));
        var oralBlock = '';
        if (aiLoading) {
            oralBlock = '<div class="tcc-loading">Generating AI oral-integrity analysis…</div>';
        } else if (oralAnalysis && (oralAnalysis.instructor_summary || oralAnalysis.natural_speech_likelihood)) {
            oralBlock = renderOralIntegrityAnalysis(oralAnalysis);
        } else {
            oralBlock = '<div class="tcc-modal-muted">AI oral analysis will appear here once generated.</div>';
        }
        return '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Lesson</div><div class="tcc-modal-muted">' + sub + '</div></div>'
            + '<div class="tcc-oral-ai-panel"><div class="tcc-modal-section-title">AI oral integrity review</div>' + oralBlock + '</div>'
            + renderAttemptCards(d.attempts || []);
    }

    function openAttemptDetails(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Progress Test Details', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        openTccModal('Progress Test Details', '<div class="tcc-loading">Loading progress test attempts…</div>');
        api('lesson_attempts_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Progress Test Details', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load attempts.') + '</div>');
                return;
            }
            var d = resp.data || {};
            var oral = d.oral_analysis || {};
            var needOral = (!oral || (!oral.instructor_summary && !oral.natural_speech_likelihood)) || d.oral_analysis_stale === true;
            if (!needOral) {
                openTccModal('Progress Test Details', buildProgressTestModalHtml(d, oral, false));
                return;
            }
            openTccModal('Progress Test Details', buildProgressTestModalHtml(d, {}, true));
            api('ai_progress_test_analysis', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (ar) {
                if (ar.ok) {
                    oral = ar.analysis || {};
                }
                openTccModal('Progress Test Details', buildProgressTestModalHtml(d, oral, false));
            }).catch(function () {
                openTccModal('Progress Test Details', buildProgressTestModalHtml(d, {}, false));
            });
        }).catch(function () {
            openTccModal('Progress Test Details', '<div class="tcc-error">Unable to load attempts.</div>');
        });
    }

    function serializeForOnclick(value) {
        return escapeHtml(JSON.stringify(value)).replace(/"/g, '&quot;');
    }

    function interventionBlock(title, items) {
        var html = '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">' + escapeHtml(title) + ' (' + (items ? items.length : 0) + ')</div>';
        if (!items || !items.length) return html + '<div class="tcc-modal-muted">No records found.</div></div>';
        html += '<div class="tcc-intervention-list">';
        items.forEach(function (item) {
            var label = item.title || item.email_type || item.event_type || item.override_type || item.action_type || ('Record #' + (item.id || ''));
            var statusText = item.delivery_status || item.status || item.sent_status || '';
            var created = item.created_at ? niceDateTime(item.created_at) : '';
            var sent = item.sent_timestamp || item.sent_at;
            var sentText = sent ? ('Sent ' + niceDateTime(sent)) : '';
            var recipientText = item.recipient_label ? ('Recipient: ' + prettyStatus(item.recipient_label)) : '';
            var meta = [statusText, created, sentText, recipientText].filter(Boolean).join(' · ');
            var isEmail = String(item.email_type || '').trim() !== '';
            var mailBtn = isEmail ? '<button type="button" class="tcc-audit-mail" style="flex-shrink:0" title="Email content" aria-label="View email" onclick="event.preventDefault();event.stopPropagation();openEmailBodyModal(' + serializeForOnclick(item) + ')">✉</button>' : '';
            html += '<div class="tcc-intervention-item" style="display:flex;align-items:flex-start;gap:8px;justify-content:space-between"><div class="tcc-intervention-clickable" style="flex:1;min-width:0" onclick="openInterventionDetailModal(' + serializeForOnclick(item) + ')"><div class="tcc-intervention-title">' + escapeHtml(label) + '</div><div class="tcc-intervention-meta">' + escapeHtml(meta || '—') + '</div></div>' + mailBtn + '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function openInterventions(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        if (!cohortId || !studentId) {
            openTccModal('Interventions', '<div class="tcc-error">Missing cohort or student id.</div>');
            return;
        }
        openTccModal('Interventions', '<div class="tcc-loading">Loading interventions…</div>');
        api('lesson_interventions_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (resp) {
            if (!resp || resp.ok === false) {
                openTccModal('Interventions', '<div class="tcc-error">' + escapeHtml((resp && (resp.message || resp.error)) || 'Unable to load interventions.') + '</div>');
                return;
            }
            var d = resp.data || {};
            var lesson = d.lesson || {};
            var subtitle = lessonId > 0 ? ((lesson.course_title || 'Module') + ' · ' + (lesson.lesson_title || 'Lesson')) : 'Entire program';
            var html = '<div class="tcc-modal-muted" style="margin-bottom:12px;">' + escapeHtml(subtitle) + '</div>';
            html += interventionBlock('Required Actions', d.required_actions || []);
            html += interventionBlock('Deadline Overrides / Extensions', d.deadline_overrides || []);
            html += interventionBlock('Email Trace', d.emails || []);
            html += interventionBlock('Progression Events', d.events || []);
            openTccModal('Interventions', html);
        }).catch(function () {
            openTccModal('Interventions', '<div class="tcc-error">Unable to load interventions.</div>');
        });
    }

    function cohortNowParts() {
        try {
            var fmt = new Intl.DateTimeFormat('en-US', {timeZone: cohortTimeZone(), year: 'numeric', month: 'numeric', day: 'numeric'});
            var y = 0;
            var m = 0;
            var d = 0;
            fmt.formatToParts(new Date()).forEach(function (p) {
                if (p.type === 'year') y = parseInt(p.value, 10);
                if (p.type === 'month') m = parseInt(p.value, 10);
                if (p.type === 'day') d = parseInt(p.value, 10);
            });
            return { y: y, m: m, d: d };
        } catch (e) {
            return { y: new Date().getUTCFullYear(), m: new Date().getUTCMonth() + 1, d: new Date().getUTCDate() };
        }
    }

    function sortTsToCohortYmd(sortTs) {
        var d = parseUtcDate(sortTs);
        if (!d) return '';
        try {
            return new Intl.DateTimeFormat('en-CA', {timeZone: cohortTimeZone(), year: 'numeric', month: '2-digit', day: '2-digit'}).format(d);
        } catch (e) {
            return '';
        }
    }

    function cohortTodayYmd() {
        var p = cohortNowParts();
        return String(p.y) + '-' + String(p.m).padStart(2, '0') + '-' + String(p.d).padStart(2, '0');
    }

    function auditRowMatchesPreset(row, preset) {
        var rowYmd = sortTsToCohortYmd(row.sort_ts);
        var d = parseUtcDate(row.sort_ts);
        var t = d ? d.getTime() : 0;
        var now = Date.now();
        if (preset === 'all' || !preset) return true;
        if (!rowYmd && !t) return true;
        if (preset === 'today') return rowYmd === cohortTodayYmd();
        if (preset === 'last7') return t >= now - 7 * 86400000 && t <= now + 86400000;
        if (preset === 'last30') return t >= now - 30 * 86400000 && t <= now + 86400000;
        if (preset === 'mtd') {
            var p = cohortNowParts();
            var start = String(p.y) + '-' + String(p.m).padStart(2, '0') + '-01';
            return rowYmd >= start && rowYmd <= cohortTodayYmd();
        }
        if (preset === 'prev_month') {
            var pm = cohortNowParts();
            var mo = pm.m - 1;
            var yr = pm.y;
            if (mo < 1) {
                mo = 12;
                yr--;
            }
            var start = String(yr) + '-' + String(mo).padStart(2, '0') + '-01';
            var dim = new Date(yr, mo, 0).getDate();
            var end = String(yr) + '-' + String(mo).padStart(2, '0') + '-' + String(dim).padStart(2, '0');
            return rowYmd >= start && rowYmd <= end;
        }
        return true;
    }

    function auditKindLabel(kind) {
        var map = { required_action: 'Required action', deadline_override: 'Deadline change', email: 'Email', progression_event: 'Engine event' };
        return map[kind] || kind;
    }

    function lessonLineFromRow(row) {
        var lid = parseInt(row.lesson_id, 10) || 0;
        var lt = row.lesson_title ? String(row.lesson_title) : (lid ? ('Lesson ' + lid) : 'Cohort-wide');
        var ct = row.course_title ? String(row.course_title) : '';
        return ct ? (ct + ' · ' + lt) : lt;
    }

    function renderAuditRows(rows, allowMail) {
        if (!rows.length) return '<div class="tcc-empty">No rows match the current filters.</div>';
        var html = '<div class="tcc-audit-feed">';
        rows.forEach(function (row) {
            var kind = String(row.kind || '');
            var chipCls = 'tcc-audit-chip' + (kind ? (' ' + kind) : '');
            var line = lessonLineFromRow(row);
            var mailBtn = allowMail && kind === 'email' ? '<button type="button" class="tcc-audit-mail" title="View email content" aria-label="View email" onclick="event.preventDefault();event.stopPropagation();openEmailBodyModal(' + serializeForOnclick(row.payload || {}) + ')">✉</button>' : '';
            var lidAttr = String(parseInt(row.lesson_id, 10) || '');
            html += '<div class="tcc-audit-row" data-lesson-id="' + escapeHtml(lidAttr) + '"><div class="tcc-audit-row-main tcc-intervention-clickable" onclick="openInterventionDetailModal(' + serializeForOnclick(row.payload || {}) + ')"><div class="tcc-audit-row-head"><span class="' + escapeHtml(chipCls) + '">' + escapeHtml(auditKindLabel(kind)) + '</span><span class="tcc-audit-ts">' + escapeHtml(niceDateTime(row.sort_ts || '')) + '</span><span class="tcc-audit-lesson" title="' + escapeHtml(line) + '">' + escapeHtml(line) + '</span></div><div class="tcc-audit-label">' + escapeHtml(row.label || '—') + '</div><div class="tcc-audit-meta">' + escapeHtml(row.meta || '—') + '</div></div>' + mailBtn + '</div>';
        });
        html += '</div>';
        return html;
    }

    function auditApplyFiltersAndSort(rows) {
        var sortOrder = (document.getElementById('auditSortOrder') && document.getElementById('auditSortOrder').value) || 'asc';
        var sortMode = (document.getElementById('auditSortMode') && document.getElementById('auditSortMode').value) || 'date';
        var preset = (document.getElementById('auditDatePreset') && document.getElementById('auditDatePreset').value) || 'all';
        var mailOn = !document.getElementById('auditToggleMail') || document.getElementById('auditToggleMail').checked;
        var reqOn = !document.getElementById('auditToggleReq') || document.getElementById('auditToggleReq').checked;
        var dlOn = !document.getElementById('auditToggleDl') || document.getElementById('auditToggleDl').checked;
        var list = rows.filter(function (row) {
            if (!auditRowMatchesPreset(row, preset)) return false;
            var k = String(row.kind || '');
            if (k === 'email' && !mailOn) return false;
            if (k === 'required_action' && !reqOn) return false;
            if (k === 'deadline_override' && !dlOn) return false;
            return true;
        });
        if (sortMode === 'course') {
            list.sort(function (a, b) {
                var ca = String(a.course_title || 'ZZZ').localeCompare(String(b.course_title || 'ZZZ'));
                if (ca !== 0) return ca;
                var la = String(a.lesson_title || '').localeCompare(String(b.lesson_title || ''));
                if (la !== 0) return la;
                return String(a.sort_ts || '').localeCompare(String(b.sort_ts || ''));
            });
            if (sortOrder === 'desc') list.reverse();
        } else {
            list.sort(function (a, b) {
                return String(a.sort_ts || '').localeCompare(String(b.sort_ts || ''));
            });
            if (sortOrder === 'desc') list.reverse();
        }
        return list;
    }

    function refreshAuditView() {
        var sid = parseInt(selectedStudentId, 10) || 0;
        if (!sid || studentAuditTimelineCacheStudentId !== sid || !studentAuditTimelineCache) return;
        var full = studentAuditTimelineCache.slice();
        var instructorRows = full.filter(function (r) { return String(r.kind || '') !== 'progression_event'; });
        var engineRows = full.filter(function (r) { return String(r.kind || '') === 'progression_event'; });
        var mainList = auditApplyFiltersAndSort(instructorRows);
        var engList = auditApplyFiltersAndSort(engineRows);
        var body = document.getElementById('studentAuditBody');
        var engBody = document.getElementById('studentEngineEventsBody');
        var pill = document.getElementById('auditCountPill');
        if (pill) pill.textContent = mainList.length + ' shown · ' + engineRows.length + ' engine';
        if (body) body.innerHTML = renderAuditRows(mainList, true);
        if (engBody) engBody.innerHTML = renderAuditRows(engList, false);
        if (pendingAuditHighlightLessonId > 0) {
            var hl = pendingAuditHighlightLessonId;
            pendingAuditHighlightLessonId = 0;
            document.querySelectorAll('.tcc-audit-row-highlight').forEach(function (n) { n.classList.remove('tcc-audit-row-highlight'); });
            var target = document.querySelector('#studentAuditBody .tcc-audit-row[data-lesson-id="' + String(hl) + '"]');
            if (target) {
                target.classList.add('tcc-audit-row-highlight');
                target.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        }
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('tcc-audit-control')) return;
        if (!t.closest || !t.closest('#tccLessonInterventionsSection')) return;
        refreshAuditView();
    });

    function buildLessonInterventionsHtml(sidNum) {
        return '<div class="tcc-li-toolbar">' +
            '<button type="button" class="tcc-btn primary active" id="liBtnLessons" onclick="switchStudentDeepTab(\'lessons\')">Lessons</button>' +
            '<button type="button" class="tcc-btn secondary" id="liBtnInterventions" onclick="switchStudentDeepTab(\'audit\')">Interventions</button>' +
            '<button type="button" class="tcc-btn warn" onclick="openSystemWatchForStudent(' + sidNum + ')">System Watch</button>' +
            '</div>' +
            '<div id="deepPaneLessons" class="tcc-li-pane" style="display:block">' +
            '<div class="tcc-lesson-timeline">' +
            '<div class="tcc-lesson-timeline-head"><div><div class="tcc-lesson-timeline-title">Lessons by module</div><div class="tcc-lesson-timeline-sub">Click summary, progress test, attempts, or interventions — each opens the modal or timeline.</div></div><span class="tcc-count-pill">Live</span></div>' +
            '<div id="studentLessonTimelineBody" class="tcc-timeline-loading">Loading lesson modules…</div>' +
            '</div></div>' +
            '<div id="deepPaneAudit" class="tcc-li-pane" style="display:none">' +
            '<div class="tcc-audit-controls-grid">' +
            '<div class="tcc-audit-field"><label for="auditSortOrder">Sort direction</label><select id="auditSortOrder" class="tcc-audit-control"><option value="asc">Oldest first</option><option value="desc">Newest first</option></select></div>' +
            '<div class="tcc-audit-field"><label for="auditSortMode">Sort by</label><select id="auditSortMode" class="tcc-audit-control"><option value="date">Date / time</option><option value="course">Course, then lesson</option></select></div>' +
            '<div class="tcc-audit-field"><label for="auditDatePreset">Date range</label><select id="auditDatePreset" class="tcc-audit-control"><option value="all">All time</option><option value="today">Today</option><option value="last7">Last 7 days</option><option value="last30">Last 30 days</option><option value="mtd">Month to date</option><option value="prev_month">Previous calendar month</option></select></div>' +
            '</div>' +
            '<div class="tcc-audit-toggles">' +
            '<label><input type="checkbox" id="auditToggleMail" class="tcc-audit-control" checked> Emails</label>' +
            '<label><input type="checkbox" id="auditToggleReq" class="tcc-audit-control" checked> Required actions</label>' +
            '<label><input type="checkbox" id="auditToggleDl" class="tcc-audit-control" checked> Deadline changes</label>' +
            '</div>' +
            '<div class="tcc-lesson-timeline-head" style="margin-top:8px;border-top:1px solid rgba(15,23,42,.06);padding-top:14px"><div><div class="tcc-lesson-timeline-title">Chronological interventions</div><div class="tcc-lesson-timeline-sub">Filtered instructor-facing events (emails, actions, deadline overrides).</div></div><span class="tcc-count-pill" id="auditCountPill">—</span></div>' +
            '<div id="studentAuditBody" class="tcc-timeline-loading">Loading…</div>' +
            '<details class="tcc-engine-details" id="engineEventsDetails"><summary>ENGINE EVENTS</summary><div id="studentEngineEventsBody" class="tcc-modal-muted" style="padding:10px 4px 4px;font-size:12px;line-height:1.45">Lower-level progression engine diagnostics. Same filters apply.</div></details>' +
            '</div>';
    }

    function switchStudentDeepTab(which) {
        which = which || 'lessons';
        var bL = document.getElementById('liBtnLessons');
        var bA = document.getElementById('liBtnInterventions');
        var pL = document.getElementById('deepPaneLessons');
        var pA = document.getElementById('deepPaneAudit');
        if (!pL || !pA) return;
        if (which === 'audit') {
            if (bL) { bL.classList.remove('active'); }
            if (bA) { bA.classList.add('active'); }
            pL.style.display = 'none';
            pA.style.display = 'block';
            var sid = parseInt(selectedStudentId, 10) || 0;
            if (sid && studentAuditTimelineCacheStudentId !== sid) {
                loadStudentInterventionsAudit();
            } else {
                refreshAuditView();
            }
        } else {
            if (bA) { bA.classList.remove('active'); }
            if (bL) { bL.classList.add('active'); }
            pA.style.display = 'none';
            pL.style.display = 'block';
        }
    }

    function loadStudentInterventionsAudit() {
        var sid = parseInt(selectedStudentId, 10) || 0;
        var body = document.getElementById('studentAuditBody');
        if (!cohortId || !sid || !body) return;
        body.innerHTML = '<div class="tcc-timeline-loading">Loading interventions audit…</div>';
        var eng = document.getElementById('studentEngineEventsBody');
        if (eng) eng.innerHTML = '<div class="tcc-timeline-loading">Loading…</div>';
        api('student_interventions_audit', { cohort_id: cohortId, student_id: sid }).then(function (resp) {
            if (!resp || resp.ok === false) {
                var err = '<div class="tcc-timeline-error">' + escapeHtml((resp && (resp.message || resp.error)) || 'Unable to load audit trail.') + '</div>';
                body.innerHTML = err;
                if (eng) eng.innerHTML = err;
                return;
            }
            var d = resp.data || {};
            studentAuditTimelineCache = Array.isArray(d.timeline) ? d.timeline.slice() : [];
            studentAuditTimelineCacheStudentId = sid;
            refreshAuditView();
        }).catch(function () {
            body.innerHTML = '<div class="tcc-timeline-error">Unable to load audit trail.</div>';
            if (eng) eng.innerHTML = '<div class="tcc-timeline-error">Unable to load engine events.</div>';
        });
    }

    function renderTheorySummaryModalInner(d, studentId, lessonId, aiBanner) {
        d = d || {};
        var lesson = d.lesson || {};
        var s = d.summary || {};
        var ai = d.ai_interpretation || {};
        var summaryHtml = rawSummaryHtml(s);
        var banner = aiBanner ? '<div style="padding:10px 12px;background:#eff6ff;border-radius:12px;border:1px solid rgba(29,79,137,.18);margin-bottom:12px;font-size:13px;line-height:1.45;color:#0f2745;font-weight:700;">' + escapeHtml(aiBanner) + '</div>' : '';
        return banner + '<div class="tcc-modal-grid"><div class="tcc-modal-section"><div class="tcc-modal-section-title">Review Status</div>' + modalStatusRows([['Module', lesson.course_title || '—'], ['Lesson', lesson.lesson_title || '—'], ['Updated', niceDateTime(s.updated_at)]]) + '<div style="margin-top:10px;">' + reviewStatusPill(s.review_status || 'neutral') + '</div></div><div class="tcc-modal-section"><div class="tcc-modal-section-title">Review Score</div>' + reviewScoreBar(s.review_score) + '</div><div class="tcc-modal-section full"><div class="tcc-modal-section-title">Student Summary</div><div class="tcc-summary-paper nb-content">' + summaryHtml + '</div></div><div class="tcc-modal-section full"><div class="tcc-modal-section-title">AI Interpretation</div>' + renderAiInterpretation(ai, studentId, lessonId) + '</div><div class="tcc-modal-section full"><div class="tcc-modal-section-title">Instructor Feedback</div><div class="tcc-modal-readable">' + escapeHtml(s.review_feedback || s.review_notes_by_instructor || 'No instructor feedback recorded for this summary yet.') + '</div></div></div>';
    }

    function openLessonSummary(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;
        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Theory Summary', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }
        var title = 'Lesson Summary';
        openTccModal(title, '<div class="tcc-loading">Loading theory summary…</div>');
        api('lesson_summary_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Theory Summary', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load summary.') + '</div>');
                return;
            }
            var d = resp.data || {};
            var lesson = d.lesson || {};
            title = lesson.lesson_title || 'Theory Summary';
            var ai = d.ai_interpretation || {};
            var needsAi = d.ai_cache_stale === true || String(ai.analysis_status || '') !== 'generated';
            function paint(banner) {
                openTccModal(title, renderTheorySummaryModalInner(d, studentId, lessonId, banner));
            }
            if (!needsAi) {
                paint('AI status: advisory analysis is stored for this summary revision.');
                return;
            }
            paint('AI status: generating advisory analysis for the current summary text…');
            api('ai_summary_analysis', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).then(function (air) {
                if (air.ok && air.analysis) {
                    d.ai_interpretation = Object.assign({}, ai, air.analysis);
                }
                paint(air.ok ? 'AI status: generated and stored; tied to the current summary revision.' : 'AI status: generation failed — you can retry with “Regenerate AI Analysis”.');
            }).catch(function () {
                paint('AI status: request failed — try “Regenerate AI Analysis”.');
            });
        }).catch(function () {
            openTccModal('Theory Summary', '<div class="tcc-error">Unable to load summary.</div>');
        });
    }

    function jumpToInterventionsForLesson(lessonId) {
        lessonId = parseInt(lessonId, 10) || 0;
        if (!lessonId) return;
        pendingAuditHighlightLessonId = lessonId;
        switchStudentDeepTab('audit');
    }

    function blockerCategory(issue) {
        issue = issue || {};
        var raw = String(issue.blocker_category || '').toLowerCase();
        if (raw === 'stale_bug') return 'system_bug';
        var type = String(issue.type || issue.issue_type || issue.action_type || '').toLowerCase();
        var title = String(issue.title || issue.reason || '').toLowerCase();
        if (raw === 'policy' && type.indexOf('deadline') >= 0) return 'deadline';
        if (type.indexOf('deadline') >= 0 || title.indexOf('deadline') >= 0) return 'deadline';
        if (type.indexOf('test') >= 0 || type.indexOf('attempt') >= 0 || type.indexOf('approval') >= 0) return 'progress_test';
        if (raw === 'ambiguous' || raw === 'system' || raw === 'stale_bug') return 'system_bug';
        return 'progress_test';
    }

    function blockerLabel(category) {
        if (category === 'deadline') return 'Deadline blocker';
        if (category === 'progress_test') return 'Progress test blocker';
        return 'System/bug blocker';
    }

    function blockerIcon(category) {
        if (category === 'deadline') {
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="4.5" width="17" height="16" rx="2.5" stroke="#1d4f89" stroke-width="1.8"/><path d="M8 2.8V6.2M16 2.8V6.2M3.5 9.2H20.5" stroke="#1d4f89" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="14.2" r="3.2" stroke="#1d4f89" stroke-width="1.8"/><path d="M12 12.6V14.6L13.4 15.4" stroke="#1d4f89" stroke-width="1.8" stroke-linecap="round"/></svg>';
        }
        if (category === 'progress_test') {
            return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="2.5" stroke="#1d4f89" stroke-width="1.8"/><path d="M8 12l2.2 2.2L16.5 8" stroke="#1d4f89" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.6 21 19.2H3L12 3.6Z" stroke="#1d4f89" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 9.2V13.4M12 16.8h.01" stroke="#1d4f89" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }

    function timelineTs(item) {
        return item.ts || item.timestamp || item.created_at || item.sent_at || '';
    }

    function renderChronologicalTimeline(items) {
        if (!items.length) return '<div class="tcc-empty">No timeline records found for this blocker.</div>';
        var html = '<div class="tcc-intervention-list">';
        items.sort(function (a, b) {
            var ta = new Date(String(timelineTs(a) || '').replace(' ', 'T') + 'Z').getTime();
            var tb = new Date(String(timelineTs(b) || '').replace(' ', 'T') + 'Z').getTime();
            if (isNaN(ta)) ta = 0;
            if (isNaN(tb)) tb = 0;
            return ta - tb;
        });
        items.forEach(function (item) {
            var click = item.kind === 'email'
                ? 'openEmailBodyModal(' + serializeForOnclick(item.payload || {}) + ')'
                : 'openInterventionDetailModal(' + serializeForOnclick(item.payload || item) + ')';
            html += '<div class="tcc-intervention-item tcc-intervention-clickable" onclick="' + click + '"><div class="tcc-intervention-title">' + escapeHtml(item.label || 'Timeline') + '</div><div class="tcc-intervention-meta">' + escapeHtml(niceDateTime(timelineTs(item)) + ' · ' + (item.meta || '—')) + '</div></div>';
        });
        html += '</div>';
        return html;
    }

    function findLessonRow(lessons, lessonId) {
        lessonId = parseInt(lessonId, 10) || 0;
        for (var i = 0; i < (lessons || []).length; i++) {
            if (parseInt(lessons[i].lesson_id || 0, 10) === lessonId) return lessons[i];
        }
        return null;
    }

    function latestDeadlineReason(interventions) {
        var actions = (interventions && interventions.required_actions) || [];
        for (var i = 0; i < actions.length; i++) {
            var a = actions[i];
            var type = String(a.action_type || '');
            if (type === 'deadline_reason_submission') {
                var raw = a.decision_payload_json || a.payload_json || a.response_payload_json || a.metadata_json || '';
                if (raw) {
                    try {
                        var parsed = JSON.parse(raw);
                        return parsed.reason || parsed.student_reason || parsed.explanation || raw;
                    } catch (e) {
                        return raw;
                    }
                }
                return a.decision_notes || a.notes || a.title || 'Deadline reason action found, but no readable reason text was supplied by the API.';
            }
        }
        return 'No deadline reason text was returned by the current read-only API response.';
    }

    function renderApprovalContextModal(issue, summaryResp, attemptsResp, interventionsResp, lessonsResp) {
        issue = issue || {};
        var studentId = parseInt(issue.student_id || selectedStudentId || 0, 10);
        var lessonId = parseInt(issue.lesson_id || 0, 10);
        var category = blockerCategory(issue);
        var lessonInfo = (summaryResp && summaryResp.data && summaryResp.data.lesson) || (attemptsResp && attemptsResp.data && attemptsResp.data.lesson) || (interventionsResp && interventionsResp.data && interventionsResp.data.lesson) || {};
        var lessonRow = findLessonRow((lessonsResp && lessonsResp.lessons) || [], lessonId) || {};
        var interventions = (interventionsResp && interventionsResp.data) || {};
        var attempts = (attemptsResp && attemptsResp.data && attemptsResp.data.attempts) || [];
        var summary = (summaryResp && summaryResp.data && summaryResp.data.summary) || {};
        var summaryHtml = rawSummaryHtml(summary);
        var originalDeadline = lessonRow.original_deadline_utc || lessonInfo.original_deadline_utc || '';
        var effectiveDeadline = lessonRow.effective_deadline_utc || lessonInfo.effective_deadline_utc || '';
        var timeline = [];
        var actions = interventions.required_actions || [];
        var emails = interventions.emails || [];
        var overrides = interventions.deadline_overrides || [];
        var events = interventions.events || [];
        var latest = attempts.length ? attempts[0] : null;

        if (category === 'deadline') {
            if (originalDeadline) timeline.push({kind: 'event', ts: originalDeadline, label: 'Deadline expiration', meta: 'Original deadline', payload: {timestamp: originalDeadline, type: 'deadline_expiration'}});
            actions.forEach(function (a) {
                if (String(a.action_type || '') === 'deadline_reason_submission') {
                    timeline.push({kind: 'event', ts: a.created_at || a.opened_at || '', label: 'Student reason submission', meta: (a.title || a.status || 'deadline_reason_submission'), payload: a});
                    if (a.decision_notes || a.decision_payload_json) {
                        timeline.push({kind: 'event', ts: a.updated_at || a.completed_at || a.created_at || '', label: 'AI approval decision', meta: (a.status || 'decision_recorded'), payload: a});
                    }
                }
            });
            overrides.forEach(function (o) {
                timeline.push({kind: 'event', ts: o.created_at || '', label: 'Extension granted', meta: (o.override_deadline_utc ? ('New deadline ' + niceDateTime(o.override_deadline_utc)) : 'Deadline override'), payload: o});
            });
            emails.forEach(function (e) {
                timeline.push({kind: 'email', ts: e.sent_timestamp || e.sent_at || e.created_at || '', label: 'Email sent', meta: (e.delivery_status || e.sent_status || 'sent') + ' · ' + (e.recipient_label || 'student'), payload: e});
            });
        } else if (category === 'progress_test') {
            if (summary.updated_at || summary.created_at) {
                timeline.push({kind: 'event', ts: summary.updated_at || summary.created_at, label: 'Summary created', meta: summary.review_status || 'summary', payload: summary});
            }
            attempts.forEach(function (a) {
                timeline.push({kind: 'event', ts: a.completed_at || a.updated_at || a.created_at || '', label: 'Attempt history', meta: 'Attempt ' + (a.attempt || '—') + ' · ' + (a.formal_result_code || a.status || '—'), payload: a});
            });
            actions.forEach(function (a) {
                timeline.push({kind: 'event', ts: a.updated_at || a.created_at || '', label: 'Instructor action', meta: (a.action_type || 'required_action') + ' · ' + (a.status || '—'), payload: a});
            });
            if (summary.review_status || summary.review_score !== null) {
                timeline.push({kind: 'event', ts: summary.updated_at || '', label: 'AI evaluation', meta: (summary.review_status || 'review') + (summary.review_score !== null && summary.review_score !== undefined ? (' · ' + summary.review_score + '%') : ''), payload: summary});
            }
        } else {
            timeline.push({kind: 'event', ts: issue.created_at || issue.updated_at || '', label: 'Human-readable explanation', meta: issue.title || issue.summary || issue.issue_type || 'System issue', payload: issue});
            timeline.push({kind: 'event', ts: issue.created_at || issue.updated_at || '', label: 'JSON evidence', meta: 'Open detail', payload: issue.evidence || issue});
            emails.forEach(function (e) {
                timeline.push({kind: 'email', ts: e.sent_timestamp || e.sent_at || e.created_at || '', label: 'Email sent', meta: (e.delivery_status || e.sent_status || 'sent') + ' · ' + (e.recipient_label || 'student'), payload: e});
            });
        }

        var html = '<div class="tcc-modal-grid">';
        html += '<div class="tcc-modal-section"><div class="tcc-modal-section-title">Blocker Category</div><div style="display:flex;align-items:center;gap:8px;font-weight:900;color:#102845;">' + blockerIcon(category) + '<span>' + escapeHtml(blockerLabel(category)) + '</span></div>' + modalStatusRows([['Issue', issue.title || issue.issue_type || issue.type || '—'], ['Module', lessonInfo.course_title || lessonRow.course_title || '—'], ['Lesson', lessonInfo.lesson_title || lessonRow.lesson_title || issue.lesson_title || '—']]) + '</div>';
        html += '<div class="tcc-modal-section"><div class="tcc-modal-section-title">Timeline Context</div>' + modalStatusRows([['Original Deadline', niceDateTime(originalDeadline)], ['Effective Deadline', niceDateTime(effectiveDeadline)], ['Completed', niceDateTime(lessonRow.completed_at)], ['Timing', lessonRow.deadline_delta_label || '—'], ['Student ID', studentId || '—']]) + '</div>';
        html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Chronological Timeline</div>' + renderChronologicalTimeline(timeline) + '</div>';
        if (category === 'progress_test') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Progress Test Attempt Context</div>' + (latest ? modalStatusRows([['Latest Attempt', latest.attempt || '—'], ['Latest Status', latest.status || '—'], ['Latest Result', latest.formal_result_code || '—'], ['Latest Score', latest.score_pct !== null && latest.score_pct !== undefined ? latest.score_pct + '%' : '—'], ['Completed', niceDateTime(latest.completed_at)]]) : '<div class="tcc-modal-muted">No progress test attempts were returned for this lesson.</div>') + '</div>';
        }
        if (summaryHtml && summaryHtml !== '—') {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Summary Snapshot</div><div class="tcc-summary-paper nb-content">' + summaryHtml + '</div></div>';
        }
        if (category === 'system_bug' && issueCanOneClickRepair(issue)) {
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Repair</div><div class="tcc-modal-muted" style="margin-bottom:10px;">This stale bug blocker is marked repairable by API and can be fixed with the safe repair endpoint.</div><button class="tcc-btn fix" type="button" data-issue-json="' + escapeHtml(JSON.stringify(issue)) + '" onclick="executeTccRepairButton(this)">Fix Issue</button></div>';
        }
        html += '<div class="tcc-modal-section full"><div class="tcc-approval-alert">Modal is read-only diagnostic and navigation context only. Decision execution remains outside this modal.</div></div>';
        html += '</div>';

        openTccModal('Review Context', html, 'Read-Only Workflow Context');
    }

    function openApprovalContextFromIssue(issue) {
        issue = issue || {};
        var studentId = parseInt(issue.student_id || selectedStudentId || 0, 10);
        var lessonId = parseInt(issue.lesson_id || 0, 10);
        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Instructor Approval Context', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>', 'Read-Only Workflow Context');
            return;
        }
        openTccModal('Instructor Approval Context', '<div class="tcc-loading">Loading read-only context from official TCC API endpoints…</div>', 'Read-Only Workflow Context');
        Promise.all([
            api('lesson_summary_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).catch(function () { return {ok:false,error:'summary_load_failed'}; }),
            api('lesson_attempts_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).catch(function () { return {ok:false,error:'attempts_load_failed'}; }),
            api('lesson_interventions_detail', {cohort_id: cohortId, student_id: studentId, lesson_id: lessonId}).catch(function () { return {ok:false,error:'interventions_load_failed'}; }),
            api('student_lessons', {cohort_id: cohortId, student_id: studentId}).catch(function () { return {ok:false,error:'lessons_load_failed',lessons:[]}; })
        ]).then(function (results) {
            renderApprovalContextModal(issue, results[0], results[1], results[2], results[3]);
        }).catch(function () {
            openTccModal('Instructor Approval Context', '<div class="tcc-error">Unable to load approval context.</div>', 'Read-Only Workflow Context');
        });
    }

    function barClass(value, average, higherIsBetter) {
        var v = parseFloat(value);
        var a = parseFloat(average);
        if (isNaN(v)) return 'warn';
        if (isNaN(a)) return higherIsBetter ? (v >= 75 ? 'ok' : (v >= 70 ? 'warn' : 'danger')) : (v === 0 ? 'ok' : 'warn');
        if (higherIsBetter) return (v >= a && v >= 75) ? 'ok' : (v >= 70 ? 'warn' : 'danger');
        if (v <= a) return 'ok';
        if (v <= a * 1.5 + 0.5) return 'warn';
        return 'danger';
    }

    function metricPct(value, average, higherIsBetter) {
        var v = parseFloat(value);
        var a = parseFloat(average);
        if (isNaN(v)) return 0;
        if (higherIsBetter) return clampPct(v);
        return clampPct((v / Math.max(v, a * 2, 1)) * 100);
    }

    function metricTile(title, value, barPct, barCls, sub) {
        return '<div class="tcc-snapshot-tile"><div class="tcc-snapshot-label">' + escapeHtml(title) + '</div><div class="tcc-snapshot-value">' + escapeHtml(value) + '</div><div class="tcc-mini-bar"><span class="' + escapeHtml(barCls) + '" style="width:' + clampPct(barPct) + '%;"></span></div><div class="tcc-snapshot-sub">' + escapeHtml(sub) + '</div></div>';
    }

    function loadCohorts() {
        api('cohort_overview').then(function (data) {
            var select = document.getElementById('cohortSelect');
            select.innerHTML = '';
            if (!data.ok || !data.cohorts || !data.cohorts.length) {
                select.innerHTML = '<option value="0">No cohorts available</option>';
                return;
            }
            data.cohorts.forEach(function (c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                if (c.timezone || c.cohort_timezone) opt.setAttribute('data-timezone', c.timezone || c.cohort_timezone);
                select.appendChild(opt);
            });
            cohortId = parseInt(data.cohorts[0].id, 10) || 0;
            window.tccCohortTimezone = data.cohorts[0].timezone || data.cohorts[0].cohort_timezone || 'UTC';
            select.value = cohortId;
            loadAll();
        }).catch(function () {
            showError('healthStrip', 'Unable to load cohorts.');
        });
    }

    function loadAll() {
        if (!cohortId) return;
        selectedStudentId = 0;
        studentAuditTimelineCache = null;
        studentAuditTimelineCacheStudentId = 0;
        setNeedsMyActionSectionVisible(true);
        setStudentDeepDiveSectionVisible(false);
        setLessonInterventionsSectionVisible(false);
        document.getElementById('studentPanelCount').textContent = 'Select student';
        document.getElementById('studentPanel').className = 'tcc-student-placeholder';
        document.getElementById('studentPanel').innerHTML = 'Tap a student avatar in the Cohort Radar, choose a student from the selector, or tap a student in the Action Queue to open the student deep dive here.';
        var liInner = document.getElementById('lessonInterventionsInner');
        if (liInner) liInner.innerHTML = '';
        loadOverview();
        loadQueue();
    }

    function loadOverview() {
        document.getElementById('healthStrip').innerHTML = '<div class="tcc-loading">Loading cohort health…</div>';
        document.getElementById('radarAvatars').innerHTML = '';
        document.getElementById('radarCount').textContent = '—';
        api('cohort_overview', {cohort_id: cohortId}).then(function (data) {
            if (!data.ok) {
                showError('healthStrip', data.message || data.error || 'Unable to load cohort overview.');
                return;
            }
            renderHealth(data);
            renderRadar(data);
        }).catch(function () {
            showError('healthStrip', 'Unable to load cohort overview.');
        });
    }

    function renderHealth(data) {
        var s = data.summary || {};
        document.getElementById('healthCount').textContent = (s.student_count || 0) + ' students';
        document.getElementById('healthStrip').innerHTML =
            '<div class="tcc-health-card"><div class="tcc-health-label">Students</div><div class="tcc-health-value">' + escapeHtml(s.student_count || 0) + '</div><div class="tcc-health-note">Active in this cohort</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">On Track</div><div class="tcc-health-value">' + escapeHtml(s.on_track_count || 0) + '</div><div class="tcc-health-note">No immediate action</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">At Risk</div><div class="tcc-health-value">' + escapeHtml(s.at_risk_count || 0) + '</div><div class="tcc-health-note">Needs monitoring</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">Blocked</div><div class="tcc-health-value">' + escapeHtml(s.blocked_count || 0) + '</div><div class="tcc-health-note">Action required</div></div>';
    }

    function renderRadar(data) {
        var container = document.getElementById('radarAvatars');
        var count = document.getElementById('radarCount');
        var select = document.getElementById('studentSelect');
        var students = data.students || [];
        cohortStudents = students;
        container.innerHTML = '';
        select.innerHTML = '<option value="0">Select student…</option>';
        count.textContent = students.length + ' student' + (students.length === 1 ? '' : 's');
        if (!students.length) {
            container.innerHTML = '<div class="tcc-empty">No students found for this cohort.</div>';
            return;
        }
        students.forEach(function (s, idx) {
            var progress = clampPct(s.progress_pct);
            var color = radarColor(s);
            var opt = document.createElement('option');
            opt.value = s.student_id || 0;
            opt.textContent = (s.name || 'Student') + ' · ' + progress + '%';
            select.appendChild(opt);
            var el = document.createElement('div');
            el.className = 'tcc-radar-avatar ' + color;
            el.style.left = progress + '%';
            el.style.top = (64 + (idx % 3) * 7) + 'px';
            el.title = (s.name || 'Student') + ' · ' + progress + '% · ' + (s.state || '') + (s.motivation_detail ? ' — ' + String(s.motivation_detail) : '');
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
            el.setAttribute('aria-label', (s.name || 'Student') + ' progress ' + progress + ' percent');
            el.setAttribute('data-student-id', s.student_id || 0);
            var path = photoPath(s);
            var inner = path !== '' ? '<span class="tcc-avatar-inner"><img src="' + escapeHtml(path) + '" alt="' + escapeHtml(s.name || 'Student') + '"></span>' : '<span class="tcc-avatar-inner">' + escapeHtml(s.avatar_initials || 'S') + '</span>';
            el.innerHTML = '<span class="tcc-radar-score">' + progress + '%</span>' + inner + '<span class="tcc-radar-label">' + escapeHtml(firstName(s.name)) + '</span>';
            el.onclick = function () { loadStudentPanel(s.student_id); };
            el.onkeydown = function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); el.click(); } };
            container.appendChild(el);
        });
    }

    function selectStudentInUi(studentId) {
        selectedStudentId = parseInt(studentId, 10) || 0;
        var select = document.getElementById('studentSelect');
        if (select) select.value = String(selectedStudentId || 0);
        Array.prototype.forEach.call(document.querySelectorAll('.tcc-radar-avatar'), function (el) {
            el.classList.remove('selected');
            if (parseInt(el.getAttribute('data-student-id') || '0', 10) === selectedStudentId) el.classList.add('selected');
        });
    }

    function summaryQualityPct(status, score) {
        if (score !== null && score !== undefined && score !== '') return clampPct(score);
        status = String(status || '').toLowerCase();
        if (status === 'acceptable') return 82;
        if (status === 'pending') return 58;
        if (status === 'needs_revision') return 32;
        if (status === 'rejected') return 20;
        return 0;
    }

    function summaryQualityClass(status, score) {
        status = String(status || '').toLowerCase();
        if (status === 'acceptable') return 'ok';
        if (status === 'pending') return 'warn';
        if (status === 'needs_revision' || status === 'rejected') return 'danger';
        if (score !== null && score !== undefined && Number(score) > 0) return 'info';
        return 'neutral';
    }

    function lessonDeltaClass(l) {
        var c = String(l.deadline_delta_class || '').toLowerCase();
        if (c === 'early' || c === 'on_time' || c === 'ok') return 'ok';
        if (c === 'late' || c === 'danger') return 'danger';
        if (c === 'warn') return 'warn';
        var label = String(l.deadline_delta_label || '').toLowerCase();
        if (label.indexOf('late') >= 0) return 'danger';
        if (label.indexOf('early') >= 0 || label.indexOf('on time') >= 0) return 'ok';
        return 'neutral';
    }

    function renderSummaryQuality(l) {
        var status = l.summary_status || 'not_started';
        var pct = summaryQualityPct(status, l.summary_score);
        var cls = summaryQualityClass(status, l.summary_score);
        return '<div class="tcc-quality-wrap"><div class="tcc-quality-top">' + pct + '%</div><div class="tcc-quality-bar"><span class="' + escapeHtml(cls) + '" style="width:' + pct + '%;"></span></div><div class="tcc-small-grey">' + escapeHtml(prettyStatus(status)) + '</div></div>';
    }

    function renderTestScore(l) {
        var score = (l.last_score !== null && l.last_score !== undefined) ? Number(l.last_score) : null;
        var cls = l.test_passed ? 'ok' : (score !== null ? 'danger' : 'neutral');
        var label = score !== null ? (score + '%') : '—';
        return '<span class="tcc-score-pill ' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function renderLessonTimeline(lessons) {
        var target = document.getElementById('studentLessonTimelineBody');
        if (!target) return;
        if (!lessons || !lessons.length) {
            target.innerHTML = '<div class="tcc-empty">No lesson rows returned for this student.</div>';
            return;
        }
        var modules = [];
        var moduleMap = {};
        lessons.forEach(function (l) {
            var key = String(l.course_id || l.course_title || 'module');
            if (!moduleMap[key]) {
                moduleMap[key] = {course_id: l.course_id || 0, course_title: l.course_title || 'Module', lessons: []};
                modules.push(moduleMap[key]);
            }
            moduleMap[key].lessons.push(l);
        });
        var html = '<div class="tcc-module-stack">';
        modules.forEach(function (mod, moduleIndex) {
            var total = mod.lessons.length;
            var passed = mod.lessons.filter(function (l) { return !!l.test_passed; }).length;
            var avgScores = mod.lessons.filter(function (l) { return l.last_score !== null && l.last_score !== undefined; }).map(function (l) { return Number(l.last_score); });
            var avg = avgScores.length ? Math.round(avgScores.reduce(function (a, b) { return a + b; }, 0) / avgScores.length) : null;
            html += '<details class="tcc-module-card" ' + (moduleIndex === 0 ? 'open' : '') + '><summary><div class="tcc-module-head"><div class="tcc-module-badge">' + (moduleIndex + 1) + '</div><div><div class="tcc-module-label">Module</div><div class="tcc-module-title">' + escapeHtml(mod.course_title) + '</div></div><div><div class="tcc-module-mini">Progress</div><div class="tcc-module-value">' + passed + '/' + total + '</div></div><div><div class="tcc-module-mini">Avg Score</div><div class="tcc-module-value">' + (avg !== null ? avg + '%' : '—') + '</div></div></div></summary>';
            html += '<div class="tcc-module-body"><div class="tcc-instructor-lesson-wrap"><table class="tcc-instructor-lesson-table"><colgroup><col style="width:27%"><col style="width:18%"><col style="width:13%"><col style="width:15%"><col style="width:11%"><col style="width:8%"><col style="width:8%"></colgroup><thead><tr><th>Lesson</th><th>Deadlines</th><th>Finished</th><th>Summary Quality</th><th>Progress Test</th><th>Attempts</th><th>Interventions</th></tr></thead><tbody>';
            mod.lessons.forEach(function (l, lessonIndex) {
                var lessonId = parseInt(l.lesson_id || 0, 10);
                var studentId = parseInt(selectedStudentId || 0, 10);
                var ext = Number(l.extension_count || 0);
                var extText = ext === 1 ? '1 Extension' : ext + ' Extensions';
                var extClass = ext > 0 ? 'tcc-extension-warn' : 'tcc-extension-ok';
                var deltaClass = lessonDeltaClass(l);
                var interventionCount = Number(l.intervention_count || 0);
                html += '<tr class="tcc-lesson-row">';
                html += '<td><div class="tcc-lesson-main"><span class="tcc-lesson-num">' + (lessonIndex + 1) + '.</span><div class="tcc-lesson-name">' + escapeHtml(l.lesson_title || 'Lesson') + '</div></div></td>';
                html += '<td><div class="tcc-date-stack"><div class="tcc-date-line"><span>Orig</span>' + escapeHtml(niceDate(l.original_deadline_utc)) + '</div><div class="tcc-date-line"><span>Eff</span>' + escapeHtml(niceDate(l.effective_deadline_utc)) + '</div><div class="' + extClass + '">' + escapeHtml(ext > 0 ? extText : 'No Extensions') + '</div></div></td>';
                html += '<td><div class="tcc-date-stack"><div class="tcc-date-line"><span>Finished</span>' + escapeHtml(niceDate(l.completed_at)) + '</div><div class="tcc-small-grey">&nbsp;</div><div class="tcc-delta ' + deltaClass + '">' + escapeHtml(l.deadline_delta_label || '—') + '</div></div></td>';
                html += '<td><span class="tcc-lesson-click" role="button" tabindex="0" onclick="event.stopPropagation();openLessonSummary(' + studentId + ',' + lessonId + ')">' + renderSummaryQuality(l) + '</span></td>';
                html += '<td><span class="tcc-lesson-click" role="button" tabindex="0" onclick="event.stopPropagation();openAttemptDetails(' + studentId + ',' + lessonId + ')">' + renderTestScore(l) + '</span></td>';
                html += '<td><span class="tcc-lesson-click" role="button" tabindex="0" onclick="event.stopPropagation();openAttemptDetails(' + studentId + ',' + lessonId + ')"><span class="tcc-count-mini info">' + escapeHtml(l.attempt_count || 0) + '</span></span></td>';
                var ivCell = '<span class="tcc-count-mini ' + (interventionCount > 0 ? 'warn' : 'neutral') + '">' + escapeHtml(interventionCount) + '</span>';
                if (interventionCount > 0) {
                    ivCell = '<span class="tcc-lesson-click" role="button" tabindex="0" onclick="event.stopPropagation();jumpToInterventionsForLesson(' + lessonId + ')">' + ivCell + '</span>';
                }
                html += '<td>' + ivCell + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div></div></details>';
        });
        html += '</div>';
        target.innerHTML = html;
    }

    function loadStudentLessons(studentId) {
        var target = document.getElementById('studentLessonTimelineBody');
        if (target) target.innerHTML = '<div class="tcc-timeline-loading">Loading lesson timeline…</div>';
        api('student_lessons', {cohort_id: cohortId, student_id: studentId}).then(function (data) {
            if (!data.ok) {
                if (target) target.innerHTML = '<div class="tcc-timeline-error">' + escapeHtml(data.message || data.error || 'Unable to load lesson timeline.') + '</div>';
                return;
            }
            renderLessonTimeline(data.lessons || []);
        }).catch(function () {
            if (target) target.innerHTML = '<div class="tcc-timeline-error">Unable to load lesson timeline.</div>';
        });
    }

    function issueCanOneClickRepair(issue) {
        issue = issue || {};
        return issue.repair_allowed === true && String(issue.blocker_category || '') === 'stale_bug' && String(issue.repair_code || '') === 'cleanup_old_active_attempt_after_pass' && issue.evidence && parseInt(issue.evidence.test_id || 0, 10) > 0;
    }

    function repairPayloadFromIssue(issue, studentId, lessonId) {
        issue = issue || {};
        return {
            student_id: parseInt(issue.student_id || studentId || selectedStudentId || 0, 10),
            cohort_id: parseInt(issue.cohort_id || cohortId || 0, 10),
            lesson_id: parseInt(issue.lesson_id || lessonId || 0, 10),
            repair_code: String(issue.repair_code || ''),
            issue_type: String(issue.issue_type || issue.type || 'old_active_progress_test_attempt'),
            blocker_category: String(issue.blocker_category || ''),
            recurrence_key: String(issue.recurrence_key || ''),
            evidence: issue.evidence || {}
        };
    }

    function executeTccRepairButton(btn) {
        if (!btn) return;
        var issue = {};
        try { issue = JSON.parse(btn.getAttribute('data-issue-json') || '{}'); }
        catch (e) { openTccModal('Fix Issue', '<div class="tcc-error">Invalid repair payload.</div>'); return; }
        executeTccRepairFromIssue(JSON.stringify(issue), btn);
    }

    function executeTccRepairFromIssue(issueJson, btn) {
        var issue = {};
        try { issue = JSON.parse(issueJson || '{}'); }
        catch (e) { openTccModal('Fix Issue', '<div class="tcc-error">Invalid repair payload.</div>'); return; }
        var payload = repairPayloadFromIssue(issue, issue.student_id, issue.lesson_id);
        if (!issueCanOneClickRepair(issue)) {
            openTccModal('Fix Issue', '<div class="tcc-error">This issue is not eligible for one-click repair.</div>');
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Fixing…'; }
        repairApi(payload).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Fix Issue Failed', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Repair failed.') + '</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(resp, null, 2)) + '</pre>');
                return;
            }
            openTccModal('Fix Issue Complete', '<div class="tcc-empty">Stale blocker cleaned and audit log written.</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(resp, null, 2)) + '</pre>');
            if (selectedStudentId > 0) loadStudentPanel(selectedStudentId);
            loadQueue();
            loadOverview();
        }).catch(function () {
            openTccModal('Fix Issue Failed', '<div class="tcc-error">Unable to execute repair.</div>');
        }).finally(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Fix Issue'; }
        });
    }

    function loadStudentPanel(studentId) {
        selectStudentInUi(studentId);
        var panel = document.getElementById('studentPanel');
        if (!selectedStudentId || !panel) return;
        setNeedsMyActionSectionVisible(false);
        setStudentDeepDiveSectionVisible(true);
        setLessonInterventionsSectionVisible(true);
        var liInner = document.getElementById('lessonInterventionsInner');
        if (liInner) liInner.innerHTML = '<div class="tcc-loading">Loading lessons & interventions…</div>';
        document.getElementById('studentPanelCount').textContent = 'Loading';
        panel.className = '';
        panel.innerHTML = '<div class="tcc-loading">Loading student snapshot…</div>';
        api('student_snapshot', {cohort_id: cohortId, student_id: selectedStudentId}).then(function (data) {
            if (!data.ok) {
                document.getElementById('studentPanelCount').textContent = 'Error';
                panel.innerHTML = '<div class="tcc-error">' + escapeHtml(data.message || data.error || 'Unable to load student snapshot.') + '</div>';
                if (liInner) liInner.innerHTML = '';
                return;
            }
            renderStudentPanel(data);
        }).catch(function () {
            document.getElementById('studentPanelCount').textContent = 'Error';
            panel.innerHTML = '<div class="tcc-error">Unable to load student snapshot.</div>';
            if (liInner) liInner.innerHTML = '';
        });
    }

    function renderStudentPanel(data) {
        var panel = document.getElementById('studentPanel');
        var st = data.student || {};
        var p = data.progress || {};
        var c = data.comparison || {};
        var m = data.motivation || {};
        var issues = data.main_issues || [];
        var radarStudent = cohortStudents.find(function (s) { return parseInt(s.student_id, 10) === parseInt(st.student_id, 10); }) || {};
        var merged = Object.assign({}, radarStudent, st);
        var color = radarColor(radarStudent);
        var progressPct = clampPct(p.progress_pct);
        document.getElementById('studentPanelCount').textContent = (issues.length || 0) + ' issue' + (issues.length === 1 ? '' : 's');
        var issueHtml = '';
        if (!issues.length) {
            issueHtml = '<div class="tcc-empty">No current blockers or system issues returned for this student.</div>';
        } else {
            issues.slice(0, 10).forEach(function (issue) {
                issue.student_id = issue.student_id || st.student_id;
                issue.cohort_id = issue.cohort_id || cohortId;
                var lessonId = parseInt(issue.lesson_id, 10) || 0;
                var safeIssueType = String(issue.type || 'manual_check').replace(/[^a-zA-Z0-9_\-]/g, '');
                var statusLabel = (function () {
                    var t = String(issue.type || '');
                    var st = String(issue.status || '');
                    if (t === 'deadline_reason_submission' && st === 'completed') {
                        return 'reason submitted — awaiting instructor approval';
                    }
                    return st;
                })();
                var actions = '<div class="tcc-issue-actions">';
                if (String(issue.blocker_category || '') === 'policy' || officialFlowHref(issue) !== '') {
                    actions += '<button class="tcc-btn primary" type="button" data-issue-json="' + escapeHtml(JSON.stringify(issue)) + '" onclick="openApprovalContextFromButton(this)">Review</button>';
                } else {
                    actions += '<button class="tcc-btn secondary" type="button" onclick="openDebugReport(' + parseInt(st.student_id, 10) + ',' + lessonId + ',' + jsArg(safeIssueType) + ')">Inspect</button>';
                }
                if (issueCanOneClickRepair(issue)) {
                    actions += '<button class="tcc-btn fix" type="button" data-issue-json="' + escapeHtml(JSON.stringify(issue)) + '" onclick="executeTccRepairButton(this)">Fix Issue</button>';
                }
                actions += '</div>';
                issueHtml += '<div class="tcc-issue-row"><div class="tcc-issue-main"><div class="tcc-issue-title">' + escapeHtml(issue.title || issue.type || 'Issue') + '</div><div class="tcc-issue-meta">Lesson ' + escapeHtml(issue.lesson_id || '—') + ' · ' + escapeHtml(issue.lesson_title || '') + ' · ' + escapeHtml(statusLabel) + '</div></div>' + actions + '</div>';
            });
            if (issues.length > 10) {
                issueHtml += '<div class="tcc-issue-row"><div class="tcc-issue-main"><div class="tcc-issue-title">+' + (issues.length - 10) + ' more issue(s)</div><div class="tcc-issue-meta">The lesson module view below keeps the full instructor workflow organized.</div></div><div class="tcc-issue-actions"><button class="tcc-btn secondary" type="button" onclick="switchStudentDeepTab(\'lessons\');loadStudentLessons(' + parseInt(st.student_id, 10) + ')">Open Lessons</button></div></div>';
            }
        }
        var avgScore = c.avg_score !== null && c.avg_score !== undefined ? Number(c.avg_score) : null;
        var cohortAvgScore = c.cohort_avg_score !== null && c.cohort_avg_score !== undefined ? Number(c.cohort_avg_score) : null;
        var activeDl = (c.active_deadline_issues !== undefined && c.active_deadline_issues !== null && c.active_deadline_issues !== '') ? Number(c.active_deadline_issues) : Number(c.deadlines_missed || 0);
        var cohortDlRaw = (c.cohort_avg_active_deadline_issues !== undefined && c.cohort_avg_active_deadline_issues !== null && c.cohort_avg_active_deadline_issues !== '') ? c.cohort_avg_active_deadline_issues : c.cohort_avg_deadlines_missed;
        var cohortDl = Number(cohortDlRaw || 0);
        var resolvedDl = (c.resolved_deadline_issues !== undefined && c.resolved_deadline_issues !== null && c.resolved_deadline_issues !== '') ? Number(c.resolved_deadline_issues) : null;
        var dlSub = 'Cohort avg: ' + cohortDl + (resolvedDl !== null && !isNaN(resolvedDl) ? ' · Resolved (lifetime): ' + resolvedDl : '');
        var failed = Number(c.failed_attempts || 0);
        var cohortFailed = Number(c.cohort_avg_failed_attempts || 0);
        var tiles = metricTile('Progress', progressPct + '%', progressPct, '', '' + (p.passed_lessons || 0) + '/' + (p.total_lessons || 0) + ' lessons passed') + metricTile('Avg Score', avgScore !== null ? avgScore + '%' : '—', metricPct(avgScore, cohortAvgScore, true), barClass(avgScore, cohortAvgScore, true), 'Cohort Average: ' + (cohortAvgScore !== null ? cohortAvgScore + '%' : '—')) + metricTile('Active deadline issues', activeDl, metricPct(activeDl, cohortDl, false), barClass(activeDl, cohortDl, false), dlSub) + metricTile('Failed Attempts', failed, metricPct(failed, cohortFailed, false), barClass(failed, cohortFailed, false), 'Cohort Average: ' + cohortFailed);
        var motDetail = String((m.detail || m.motivation_detail || '')).trim();
        var motHint = motDetail !== '' ? '<div class="tcc-motivation-detail" style="font-size:12px;line-height:1.5;color:#475569;margin-top:10px;max-width:720px;">' + escapeHtml(motDetail) + '</div>' : '';
        var sidNum = parseInt(st.student_id, 10) || 0;
        panel.className = '';
        panel.innerHTML = '<div class="tcc-student-head"><div class="tcc-student-avatar ' + escapeHtml(color) + '">' + (photoPath(merged) !== '' ? '<img src="' + escapeHtml(photoPath(merged)) + '" alt="' + escapeHtml(st.name || 'Student') + '">' : escapeHtml(st.avatar_initials || radarStudent.avatar_initials || 'S')) + '</div><div style="min-width:0;"><div class="tcc-student-name">' + escapeHtml(st.name || 'Student') + '</div><div class="tcc-student-email">' + escapeHtml(st.email || '') + '</div></div></div><div class="tcc-student-state-row"><span class="tcc-status-pill ' + escapeHtml(m.level || '') + '">' + escapeHtml(m.label || 'Motivation signal') + '</span><span class="tcc-status-pill">Trend: ' + escapeHtml(m.trend || '—') + '</span><span class="tcc-status-pill">Issues: ' + escapeHtml(issues.length) + '</span></div>' + motHint + '<div class="tcc-snapshot-grid">' + tiles + '</div><h3 class="tcc-section-title" style="font-size:17px;margin:4px 0 10px;">Current Blockers / Issues</h3><div class="tcc-issues-list">' + issueHtml + '</div>';
        var liInner = document.getElementById('lessonInterventionsInner');
        if (liInner) liInner.innerHTML = buildLessonInterventionsHtml(sidNum);
        loadStudentLessons(st.student_id);
    }

    function loadQueue() {
        document.getElementById('actionQueue').innerHTML = '<div class="tcc-loading">Loading action queue…</div>';
        document.getElementById('queueCount').textContent = '—';
        queueItemsById = {};
        selectedQueueIds = {};
        queueFamilyMemberIds = {deadline_related: [], progress_test_failure_related: [], other: []};
        api('action_queue', {cohort_id: cohortId}).then(function (data) {
            var container = document.getElementById('actionQueue');
            var count = document.getElementById('queueCount');
            var familyControls = document.getElementById('bulkFamilyControls');
            if (!data.ok) {
                showError('actionQueue', data.message || data.error || 'Unable to load action queue.');
                if (familyControls) familyControls.innerHTML = '';
                return;
            }
            var items = data.items || [];
            count.textContent = items.length + ' item' + (items.length === 1 ? '' : 's');
            container.innerHTML = '';
            if (!items.length) {
                container.innerHTML = '<div class="tcc-empty">No instructor actions required for this cohort right now.</div>';
                if (familyControls) familyControls.innerHTML = '';
                return;
            }
            var grouped = {deadline_related: [], progress_test_failure_related: [], other: []};
            items.forEach(function (item) {
                var family = String(item.blocker_family || 'other');
                if (!grouped[family]) grouped[family] = [];
                grouped[family].push(item);
                queueItemsById[String(item.required_action_id)] = item;
                queueFamilyMemberIds[family] = queueFamilyMemberIds[family] || [];
                queueFamilyMemberIds[family].push(parseInt(item.required_action_id || 0, 10));
            });

            function renderGroup(title, list) {
                if (!list || !list.length) return '';
                var html = '<div class="tcc-module-card" style="margin-bottom:10px;"><div style="padding:10px 12px;border-bottom:1px solid rgba(15,23,42,.06);font-weight:900;color:#102845;">' + escapeHtml(title) + ' (' + list.length + ')</div><div style="padding:8px;">';
                list.forEach(function (item) {
                    var severity = item.severity || 'low';
                    var radarStudent = cohortStudents.find(function (s) { return parseInt(s.student_id, 10) === parseInt(item.student_id, 10); }) || {};
                    var color = radarColor(radarStudent.state ? radarStudent : {state: item.severity === 'high' ? 'blocked' : 'at_risk', pending_action_count: 1});
                    var avatarObj = Object.assign({}, radarStudent, {photo_path: item.photo_path || radarStudent.photo_path, avatar_initials: item.avatar_initials || radarStudent.avatar_initials, name: item.student_name});
                    var lessonId = parseInt(item.lesson_id || 0, 10);
                    var issueType = String(item.action_type || 'manual_check').replace(/[^a-zA-Z0-9_\-]/g, '');
                    var rid = parseInt(item.required_action_id || 0, 10);
                    item.type = item.action_type || item.type || '';
                    item.title = item.reason || item.title || item.action_type || 'Required action';
                    item.official_flow_url = item.official_flow_url || officialFlowHref(item);
                    html += '<div class="tcc-item"><div class="tcc-item-left"><label style="display:flex;align-items:center;gap:10px;cursor:pointer;"><input type="checkbox" class="tcc-bulk-checkbox" data-required-action-id="' + rid + '">' + avatarHtml(avatarObj, 'tcc-avatar ' + color) + '<span class="tcc-meta"><span class="tcc-name">' + escapeHtml(item.student_name || 'Student') + '</span><span class="tcc-sub">' + escapeHtml(item.lesson_title || 'No lesson title') + '</span><span class="tcc-sub">' + escapeHtml(item.reason || item.action_type || 'Required action') + '</span><span class="tcc-severity ' + escapeHtml(severity) + '">' + escapeHtml(severity) + '</span></span></label></div><div class="tcc-actions"><button class="tcc-btn primary" type="button" data-issue-json="' + escapeHtml(JSON.stringify(item)) + '" onclick="openApprovalContextFromButton(this)">Review</button><button class="tcc-btn secondary" type="button" onclick="openDebugReport(' + parseInt(item.student_id || 0, 10) + ',' + lessonId + ',' + jsArg(issueType) + ')">Inspect</button></div></div>';
                });
                html += '</div></div>';
                return html;
            }

            var html = '';
            html += renderGroup('Deadline-related (reason submissions + missed-final-deadline approvals)', grouped.deadline_related);
            html += renderGroup('Instructor approval — other (e.g. failed test)', grouped.progress_test_failure_related);
            html += renderGroup('Other', grouped.other);
            container.innerHTML = html;
            if (familyControls) {
                familyControls.innerHTML = ''
                    + '<button id="bulkSelectDeadlineFamilyBtn" class="tcc-btn secondary" type="button">Select deadline-related (' + grouped.deadline_related.length + ')</button>'
                    + '<button id="bulkSelectProgressFamilyBtn" class="tcc-btn secondary" type="button">Select instructor-approval other (' + grouped.progress_test_failure_related.length + ')</button>'
                    + '<button id="bulkSelectOtherFamilyBtn" class="tcc-btn secondary" type="button">Select other (' + grouped.other.length + ')</button>'
                    + '<button id="bulkClearSelectionBtn" class="tcc-btn secondary" type="button">Clear selection</button>';
            }

            Array.prototype.forEach.call(container.querySelectorAll('.tcc-bulk-checkbox'), function (cb) {
                cb.addEventListener('change', function () {
                    var rid = String(cb.getAttribute('data-required-action-id') || '');
                    if (!rid) return;
                    if (cb.checked) selectedQueueIds[rid] = 1;
                    else delete selectedQueueIds[rid];
                });
            });
            bindBulkFamilyControls();
        }).catch(function () {
            showError('actionQueue', 'Unable to load action queue.');
            var familyControls = document.getElementById('bulkFamilyControls');
            if (familyControls) familyControls.innerHTML = '';
        });
    }

    function setBulkSelectionByIds(ids, append) {
        ids = Array.isArray(ids) ? ids : [];
        if (!append) selectedQueueIds = {};
        ids.forEach(function (id) {
            var rid = String(parseInt(id, 10) || 0);
            if (rid !== '0') selectedQueueIds[rid] = 1;
        });
        Array.prototype.forEach.call(document.querySelectorAll('#actionQueue .tcc-bulk-checkbox'), function (cb) {
            var rid = String(cb.getAttribute('data-required-action-id') || '');
            cb.checked = !!selectedQueueIds[rid];
        });
    }

    function bindBulkFamilyControls() {
        function onClick(id, family) {
            var btn = document.getElementById(id);
            if (!btn) return;
            btn.addEventListener('click', function () {
                setBulkSelectionByIds(queueFamilyMemberIds[family] || [], false);
            });
        }
        onClick('bulkSelectDeadlineFamilyBtn', 'deadline_related');
        onClick('bulkSelectProgressFamilyBtn', 'progress_test_failure_related');
        onClick('bulkSelectOtherFamilyBtn', 'other');
        var clearBtn = document.getElementById('bulkClearSelectionBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                setBulkSelectionByIds([], false);
            });
        }
    }

    function selectedRequiredActionIds() {
        return Object.keys(selectedQueueIds).map(function (k) { return parseInt(k, 10); }).filter(function (n) { return n > 0; });
    }

    function currentBulkPayload() {
        return {
            cohort_id: cohortId,
            required_action_ids: selectedRequiredActionIds(),
            bulk_action_code: String((document.getElementById('bulkActionCode') || {}).value || ''),
            decision_notes: String((document.getElementById('bulkDecisionNotes') || {}).value || '').trim(),
            granted_extra_attempts: parseInt((document.getElementById('bulkGrantedAttempts') || {}).value || '0', 10) || 0,
            deadline_extension_days: parseInt((document.getElementById('bulkExtensionDays') || {}).value || '0', 10) || 0
        };
    }

    function postBulk(action, payload) {
        return fetch('/instructor/api/theory_control_center_api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }

    /** Keeps each bulk API request within typical reverse-proxy / browser limits; server still processes sequentially per chunk. */
    var TCC_BULK_CHUNK_SIZE = 50;

    function tccChunkIds(ids, chunkSize) {
        ids = Array.isArray(ids) ? ids.slice() : [];
        var out = [];
        var i = 0;
        for (; i < ids.length; i += chunkSize) {
            out.push(ids.slice(i, i + chunkSize));
        }
        return out;
    }

    function previewBulkAction() {
        var payload = currentBulkPayload();
        if (!payload.required_action_ids.length || !payload.bulk_action_code) {
            openTccModal('Bulk Preview', '<div class="tcc-error">Select at least one blocker and choose an action.</div>');
            return;
        }
        var allIds = payload.required_action_ids;
        var chunks = tccChunkIds(allIds, TCC_BULK_CHUNK_SIZE);
        var totalChunks = chunks.length;

        function setPreviewLoading(chunkIdx) {
            var msg = totalChunks <= 1
                ? 'Validating bulk intervention…'
                : ('Validating bulk intervention… (part ' + (chunkIdx + 1) + ' of ' + totalChunks + ')');
            openTccModal('Bulk Preview', '<div class="tcc-loading">' + msg + '</div>');
        }

        setPreviewLoading(0);

        var merged = { matched: 0, allowed: 0, mergedResults: [] };
        var idx = 0;

        function runNext() {
            if (idx >= chunks.length) {
                var html = '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Preview Summary</div>' +
                    modalStatusRows([['Requested', allIds.length], ['Matched', merged.matched], ['Allowed', merged.allowed]]) + '</div>';
                html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Per Item Validation</div><div class="tcc-intervention-list">';
                merged.mergedResults.forEach(function (r) {
                    html += '<div class="tcc-intervention-item"><div class="tcc-intervention-title">Required Action #' + escapeHtml(r.required_action_id) + ' · lesson ' + escapeHtml(r.lesson_id) + '</div><div class="tcc-intervention-meta">' + escapeHtml(r.allowed ? 'allowed' : ('blocked: ' + (r.validation_message || r.validation_error || 'unknown'))) + '</div></div>';
                });
                html += '</div></div>';
                openTccModal('Bulk Preview', html, 'Bulk Intervention');
                return;
            }

            setPreviewLoading(idx);
            var p = Object.assign({}, payload, { required_action_ids: chunks[idx] });
            postBulk('bulk_action_preview', p).then(function (resp) {
                if (!resp || !resp.ok) {
                    openTccModal('Bulk Preview', '<div class="tcc-error">' + escapeHtml((resp && (resp.error || resp.message)) || 'Preview failed.') + '<br><small>Stopped at part ' + (idx + 1) + ' of ' + totalChunks + '.</small></div>');
                    return;
                }
                merged.matched += (resp.matched_count || 0);
                merged.allowed += (resp.allowed_count || 0);
                (resp.results || []).forEach(function (r) { merged.mergedResults.push(r); });
                idx += 1;
                runNext();
            }).catch(function () {
                openTccModal('Bulk Preview', '<div class="tcc-error">Unable to preview bulk action (network or timeout).<br><small>Try again; large selections are processed in parts of ' + TCC_BULK_CHUNK_SIZE + '.</small></div>');
            });
        }

        runNext();
    }

    function executeBulkAction() {
        var payload = currentBulkPayload();
        if (!payload.required_action_ids.length || !payload.bulk_action_code) {
            openTccModal('Bulk Execute', '<div class="tcc-error">Select at least one blocker and choose an action.</div>');
            return;
        }

        var allIds = payload.required_action_ids;
        var chunks = tccChunkIds(allIds, TCC_BULK_CHUNK_SIZE);
        var totalChunks = chunks.length;

        var allBatchIds = [];
        var cumulative = { success: 0, failed: 0, skipped: 0 };
        var allResults = [];
        var affectedStudentIds = {};
        var chunkIndex = 0;

        function setExecuteLoading() {
            var msg = totalChunks <= 1
                ? 'Executing bulk intervention…'
                : ('Executing bulk intervention… (part ' + (chunkIndex + 1) + ' of ' + totalChunks + ', ' + allIds.length + ' items total)');
            openTccModal('Bulk Execute', '<div class="tcc-loading">' + msg + '</div>');
        }

        function finishSuccess() {
            var batchLabel = allBatchIds.length === 1
                ? allBatchIds[0]
                : allBatchIds.join(', ') + ' (' + allBatchIds.length + ' runs)';
            var s = cumulative;
            var html = '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Execution Summary</div>' + modalStatusRows([['Batch ID(s)', batchLabel], ['Success', s.success || 0], ['Failed', s.failed || 0], ['Skipped', s.skipped || 0]]) + '</div>';
            html += '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Per Item Result</div><div class="tcc-intervention-list">';
            allResults.forEach(function (r) {
                html += '<div class="tcc-intervention-item"><div class="tcc-intervention-title">Required Action #' + escapeHtml(r.required_action_id) + '</div><div class="tcc-intervention-meta">' + escapeHtml((r.status || 'unknown') + ' · ' + (r.message || '')) + '</div></div>';
            });
            html += '</div></div>';
            openTccModal('Bulk Execute Result', html, 'Bulk Intervention');
            loadQueue();
            loadOverview();
            if (selectedStudentId > 0 && affectedStudentIds[String(selectedStudentId)]) {
                loadStudentPanel(selectedStudentId);
            } else if (selectedStudentId <= 0) {
                var keys = Object.keys(affectedStudentIds);
                if (keys.length === 1) loadStudentPanel(parseInt(keys[0], 10));
            }
        }

        setExecuteLoading();

        function runNextChunk() {
            if (chunkIndex >= chunks.length) {
                finishSuccess();
                return;
            }

            setExecuteLoading();
            var p = Object.assign({}, payload, { required_action_ids: chunks[chunkIndex] });
            postBulk('bulk_action_execute', p).then(function (resp) {
                if (!resp || !resp.ok) {
                    openTccModal('Bulk Execute', '<div class="tcc-error">' + escapeHtml((resp && (resp.error || resp.message)) || 'Execution failed.') + '<br><small>Stopped at part ' + (chunkIndex + 1) + ' of ' + totalChunks + '. Earlier parts may already be committed — refresh the queue and run again on the remainder.</small></div>');
                    loadQueue();
                    loadOverview();
                    return;
                }
                if (resp.batch_id) {
                    allBatchIds.push(resp.batch_id);
                }
                var summ = resp.summary || {};
                cumulative.success += summ.success || 0;
                cumulative.failed += summ.failed || 0;
                cumulative.skipped += summ.skipped || 0;
                (resp.results || []).forEach(function (r) {
                    allResults.push(r);
                    if (r.status === 'success' && parseInt(r.user_id || 0, 10) > 0) {
                        affectedStudentIds[String(parseInt(r.user_id, 10))] = 1;
                    }
                });
                chunkIndex += 1;
                runNextChunk();
            }).catch(function () {
                openTccModal('Bulk Execute', '<div class="tcc-error">Unable to execute bulk action (network or timeout).<br><small>If a long run disconnected, completed chunks may already be saved. Refresh the queue and continue with remaining items.</small></div>');
                loadQueue();
                loadOverview();
            });
        }

        runNextChunk();
    }

    function openApprovalContextFromButton(btn) {
        if (!btn) return;
        var issue = {};
        try { issue = JSON.parse(btn.getAttribute('data-issue-json') || '{}'); }
        catch (e) { openTccModal('Instructor Approval Context', '<div class="tcc-error">Invalid context payload.</div>', 'Read-Only Workflow Context'); return; }
        openApprovalContextFromIssue(issue);
    }

    window.openAnswerAudioModal = openAnswerAudioModal;
    window.openInterventionDetailModal = openInterventionDetailModal;
    window.openSummaryLargeModal = openSummaryLargeModal;
    window.closeTccModal = closeTccModal;
    window.openDebugReport = openDebugReport;
    window.openSystemWatchForStudent = openSystemWatchForStudent;
    window.openLessonSummary = openLessonSummary;
    window.generateAiSummaryAnalysis = generateAiSummaryAnalysis;
    window.openAttemptDetails = openAttemptDetails;
    window.openInterventions = openInterventions;
    window.switchStudentDeepTab = switchStudentDeepTab;
    window.loadStudentInterventionsAudit = loadStudentInterventionsAudit;
    window.loadStudentLessons = loadStudentLessons;
    window.tccToggleInlineAudio = tccToggleInlineAudio;
    window.jumpToInterventionsForLesson = jumpToInterventionsForLesson;
    window.generateAiSummaryAnalysisFromButton = generateAiSummaryAnalysisFromButton;
    window.executeTccRepairButton = executeTccRepairButton;
    window.executeTccRepairFromIssue = executeTccRepairFromIssue;
    window.openApprovalContextFromButton = openApprovalContextFromButton;
    window.openApprovalContextFromIssue = openApprovalContextFromIssue;
    window.openEmailBodyModal = openEmailBodyModal;

    document.getElementById('cohortSelect').addEventListener('change', function () {
        cohortId = parseInt(this.value, 10) || 0;
        var match = Array.prototype.find.call(this.options, function (o) { return parseInt(o.value, 10) === cohortId; });
        window.tccCohortTimezone = (match && match.getAttribute('data-timezone')) || window.tccCohortTimezone || 'UTC';
        loadAll();
    });

    document.getElementById('studentSelect').addEventListener('change', function () {
        var sid = parseInt(this.value, 10) || 0;
        if (sid > 0) loadStudentPanel(sid);
        else clearStudentSelectionUi();
    });

    function refreshBulkActionHint() {
        var sel = document.getElementById('bulkActionCode');
        var hint = document.getElementById('bulkActionHint');
        if (!sel || !hint) return;
        var v = String(sel.value || '');
        if (v === 'approve_deadline_reason_submission') {
            hint.textContent = 'Use only when the row is a deadline-reason submission (student explained a missed deadline). Set +Days to extend. Does not apply to “Instructor approval required — Missed final deadline” rows — those need the second option.';
        } else if (v === 'approve_additional_attempts') {
            hint.textContent = 'Use for instructor-approval rows (failed progress test, missed final deadline, etc.). Decision notes and at least +1 attempt are required. +Days is optional where policy allows.';
        } else {
            hint.textContent = 'Choose a bulk action, select queue rows, then Preview. Always run Preview first; the list will explain any row that cannot run with the action you picked.';
        }
    }
    document.getElementById('bulkActionCode').addEventListener('change', refreshBulkActionHint);
    refreshBulkActionHint();

    document.getElementById('bulkPreviewBtn').addEventListener('click', previewBulkAction);
    document.getElementById('bulkExecuteBtn').addEventListener('click', executeBulkAction);

    document.getElementById('tccModalOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeTccModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeTccModal();
    });

    loadCohorts();
})();
</script>

<?php cw_footer(); ?>
