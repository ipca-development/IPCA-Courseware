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
.tcc-page{display:flex;flex-direction:column;gap:18px;padding-bottom:24px}.tcc-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:22px;box-shadow:0 14px 30px rgba(15,23,42,.06)}.tcc-hero{padding:20px 22px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;overflow:hidden}.tcc-hero-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end}.tcc-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.16em;font-weight:900;color:rgba(255,255,255,.72)}.tcc-hero h1{margin:6px 0 0;font-size:30px;line-height:1.02;letter-spacing:-.04em;color:#fff}.tcc-hero-sub{margin-top:8px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.84);max-width:860px}.tcc-toolbar{display:flex;align-items:flex-end;gap:10px;flex:0 0 auto}.tcc-field{display:flex;flex-direction:column;gap:6px;min-width:300px}.tcc-field label{font-size:11px;font-weight:800;color:rgba(255,255,255,.78);letter-spacing:.12em;text-transform:uppercase}.tcc-select{min-height:44px;border-radius:14px;border:1px solid rgba(15,23,42,.12);background:rgba(255,255,255,.96);color:#102845;padding:10px 12px;font:inherit;font-size:14px;font-weight:400;outline:none}.tcc-select:focus{box-shadow:0 0 0 3px rgba(255,255,255,.22)}.tcc-section{padding:18px 20px}.tcc-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}.tcc-section-title{margin:0;font-size:20px;line-height:1.1;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-section-sub{margin-top:5px;font-size:13px;color:#64748b;line-height:1.4}.tcc-count-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 11px;background:#edf4ff;color:#1d4f91;font-size:12px;font-weight:800;border:1px solid #d3e3ff;white-space:nowrap}.tcc-health-strip{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}.tcc-health-card{border-radius:17px;background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%);border:1px solid rgba(15,23,42,.07);padding:15px 16px;min-width:0}.tcc-health-label{font-size:10px;color:#64748b;font-weight:900;text-transform:uppercase;letter-spacing:.13em;white-space:nowrap}.tcc-health-value{margin-top:8px;font-size:30px;line-height:1;font-weight:900;color:#102845;letter-spacing:-.04em}.tcc-health-note{margin-top:7px;font-size:12px;color:#64748b;line-height:1.35}
.tcc-radar-wrap{position:relative;min-height:158px;margin-top:4px;padding:30px 28px 58px;border-radius:20px;background:linear-gradient(180deg,#fbfdff 0%,#f8fafc 100%);border:1px solid rgba(15,23,42,.06);overflow:hidden}.tcc-radar-line{position:absolute;top:64px;left:34px;right:34px;height:9px;background:linear-gradient(90deg,#071a31 0%,#12355f 42%,#2b6dcc 100%);border-radius:999px;box-shadow:inset 0 1px 2px rgba(255,255,255,.18),0 8px 18px rgba(18,53,95,.16)}.tcc-radar-marker{position:absolute;top:84px;font-size:10px;color:#64748b;font-weight:900;white-space:nowrap}.tcc-radar-marker.start{left:34px}.tcc-radar-marker.end{right:34px}.tcc-radar-tools{position:absolute;right:20px;bottom:16px;display:flex;gap:8px;align-items:center;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);border:1px solid rgba(15,23,42,.08);border-radius:16px;padding:8px 10px;box-shadow:0 10px 24px rgba(15,23,42,.08)}.tcc-radar-tools label{font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.1em;white-space:nowrap}.tcc-student-select{min-height:36px;min-width:230px;border-radius:11px;border:1px solid rgba(15,23,42,.14);background:#fff;color:#102845;padding:7px 10px;font:inherit;font-size:13px;font-weight:400;outline:none}.tcc-radar-avatar{position:absolute;top:64px;transform:translate(-50%,-50%);width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:12px;cursor:pointer;box-shadow:0 8px 20px rgba(15,23,42,.22);background:#12355f;border:4px solid #fff;transition:transform .15s ease,box-shadow .15s ease;user-select:none;overflow:visible}.tcc-radar-avatar:hover{transform:translate(-50%,-50%) scale(1.05);box-shadow:0 12px 26px rgba(15,23,42,.30)}.tcc-radar-avatar.selected{outline:3px solid rgba(18,53,95,.28);outline-offset:4px}.tcc-radar-avatar .tcc-avatar-inner{position:relative;width:40px;height:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff}.tcc-radar-avatar img,.tcc-avatar img,.tcc-student-avatar img{width:100%;height:100%;object-fit:cover;display:block}.tcc-radar-avatar.green{border-color:#16a34a}.tcc-radar-avatar.orange{border-color:#f59e0b}.tcc-radar-avatar.red{border-color:#dc2626}.tcc-radar-avatar.blue{border-color:#2563eb}.tcc-radar-avatar.purple{border-color:#7c3aed}.tcc-radar-score{position:absolute;top:-29px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:900;color:#334155;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:999px;padding:3px 7px;white-space:nowrap}.tcc-radar-label{position:absolute;top:52px;left:50%;transform:translateX(-50%);font-size:11px;font-weight:900;color:#334155;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis}.tcc-radar-legend{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}.tcc-legend-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 9px;background:#f8fafc;border:1px solid rgba(15,23,42,.06);font-size:11px;font-weight:800;color:#475569}.tcc-dot{width:9px;height:9px;border-radius:999px;display:inline-block}.tcc-dot.green{background:#16a34a}.tcc-dot.orange{background:#f59e0b}.tcc-dot.red{background:#dc2626}.tcc-dot.blue{background:#2563eb}.tcc-dot.purple{background:#7c3aed}
.tcc-student-placeholder{padding:18px;border-radius:18px;border:1px dashed rgba(15,23,42,.16);background:#fbfdff;color:#64748b;font-size:14px;line-height:1.5}.tcc-student-head{display:flex;align-items:center;gap:14px;margin-bottom:14px}.tcc-student-avatar{width:58px;height:58px;border-radius:20px;background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;flex:0 0 auto;overflow:hidden;border:4px solid #dbe7f4}.tcc-student-avatar.green{border-color:#16a34a}.tcc-student-avatar.orange{border-color:#f59e0b}.tcc-student-avatar.red{border-color:#dc2626}.tcc-student-avatar.blue{border-color:#2563eb}.tcc-student-avatar.purple{border-color:#7c3aed}.tcc-student-name{font-size:24px;line-height:1.05;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-student-email{margin-top:5px;font-size:13px;color:#64748b}.tcc-student-state-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.tcc-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 10px;background:#f8fafc;border:1px solid rgba(15,23,42,.08);font-size:12px;font-weight:900;color:#334155}.tcc-status-pill.strong{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.tcc-status-pill.stable{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.tcc-status-pill.drifting{background:#fef3c7;color:#92400e;border-color:#fde68a}.tcc-status-pill.needs_contact{background:#fee2e2;color:#991b1b;border-color:#fca5a5}.tcc-snapshot-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}.tcc-snapshot-tile{border-radius:17px;background:#f8fafc;border:1px solid rgba(15,23,42,.07);padding:14px 15px;min-width:0}.tcc-snapshot-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}.tcc-snapshot-value{margin-top:8px;font-size:28px;font-weight:900;line-height:1;color:#102845;letter-spacing:-.04em}.tcc-snapshot-sub{margin-top:8px;font-size:12px;color:#64748b;line-height:1.4}.tcc-mini-bar{height:9px;border-radius:999px;background:#e5eef7;overflow:hidden;margin-top:10px}.tcc-mini-bar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#12355f 0%,#2b6dcc 100%)}.tcc-mini-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}.tcc-mini-bar span.warn{background:linear-gradient(90deg,#d97706 0%,#f59e0b 100%)}.tcc-mini-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}
.tcc-issues-list{display:flex;flex-direction:column;gap:9px}.tcc-issue-row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:11px 12px;border-radius:15px;background:#fbfdff;border:1px solid rgba(15,23,42,.06)}.tcc-issue-title{font-size:13px;font-weight:900;color:#102845;line-height:1.35}.tcc-issue-meta{margin-top:4px;font-size:12px;color:#64748b;line-height:1.35}.tcc-panel-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.tcc-queue{display:flex;flex-direction:column;gap:12px}.tcc-item{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px;border-radius:16px;background:#f8fbfd;border:1px solid rgba(15,23,42,.06)}.tcc-item-left{display:flex;gap:12px;align-items:center;min-width:0}.tcc-avatar{width:44px;height:44px;border-radius:50%;background:#12355f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;flex:0 0 auto;overflow:hidden;border:3px solid #dbe7f4}.tcc-avatar.green{border-color:#16a34a}.tcc-avatar.orange{border-color:#f59e0b}.tcc-avatar.red{border-color:#dc2626}.tcc-avatar.blue{border-color:#2563eb}.tcc-avatar.purple{border-color:#7c3aed}.tcc-meta{display:flex;flex-direction:column;min-width:0}.tcc-name{font-weight:900;color:#102845;line-height:1.25}.tcc-sub{font-size:12px;color:#64748b;line-height:1.35;overflow:hidden;text-overflow:ellipsis}.tcc-severity{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;align-self:flex-start}.tcc-severity.high{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.tcc-severity.medium{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.tcc-severity.low{background:#e0f2fe;color:#075985;border:1px solid #bae6fd}.tcc-actions{display:flex;gap:8px;flex:0 0 auto}.tcc-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border-radius:11px;font-size:12px;font-weight:900;border:1px solid rgba(15,23,42,.08);cursor:pointer;text-decoration:none}.tcc-btn.primary{background:#12355f;color:#fff;border-color:#12355f}.tcc-btn.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}.tcc-btn.secondary{background:#f1f5f9;color:#334155}.tcc-empty,.tcc-loading,.tcc-timeline-loading{padding:16px;border-radius:16px;border:1px dashed rgba(15,23,42,.15);background:#fbfdff;color:#64748b;font-size:14px}.tcc-error,.tcc-timeline-error{padding:14px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-size:14px}
.tcc-issue-row{align-items:center}.tcc-issue-main{min-width:0;flex:1}.tcc-issue-actions{display:flex;gap:7px;flex:0 0 auto;align-items:center;flex-wrap:wrap;justify-content:flex-end}.tcc-issue-actions .tcc-btn{min-height:30px;padding:7px 10px;font-size:11px}
	
.tcc-btn.fix{background:#166534;color:#fff;border-color:#166534}

.tcc-btn.fix[disabled]{opacity:.55;cursor:not-allowed}	
	
	.tcc-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:18px}.tcc-modal-overlay.open{display:flex}.tcc-modal-card{width:min(980px,96vw);max-height:88vh;overflow:hidden;background:#fff;border-radius:22px;border:1px solid rgba(15,23,42,.10);box-shadow:0 24px 70px rgba(15,23,42,.35);display:flex;flex-direction:column}.tcc-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:17px 19px;border-bottom:1px solid rgba(15,23,42,.08);background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%);color:#fff}.tcc-modal-kicker{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.72)}.tcc-modal-title{margin-top:5px;font-size:20px;font-weight:900;line-height:1.1;color:#fff}.tcc-modal-close{border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:12px;width:34px;height:34px;font-size:24px;line-height:1;cursor:pointer}.tcc-modal-body{padding:16px 18px;overflow:auto}.tcc-debug-pre{white-space:pre-wrap;background:#0f172a;color:#e2e8f0;border-radius:16px;padding:14px;font-size:12px;line-height:1.45;overflow:auto}.tcc-debug-meta{font-size:13px;color:#475569;margin-bottom:10px;line-height:1.45}
@media (max-width:1100px){.tcc-hero-head{flex-direction:column;align-items:stretch}.tcc-toolbar{width:100%}.tcc-field{min-width:0;width:100%}.tcc-health-strip,.tcc-snapshot-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:700px){.tcc-hero{padding:18px}.tcc-hero h1{font-size:25px}.tcc-health-strip,.tcc-snapshot-grid{grid-template-columns:1fr}.tcc-radar-wrap{min-height:214px;padding-left:18px;padding-right:18px;overflow-x:auto}.tcc-radar-line{left:24px;right:24px}.tcc-radar-tools{position:absolute;left:16px;right:16px;bottom:14px;justify-content:space-between}.tcc-student-select{min-width:0;width:100%}.tcc-item{align-items:flex-start;flex-direction:column}.tcc-actions{width:100%;justify-content:flex-start}.tcc-section-head{flex-direction:column}.tcc-student-head{align-items:flex-start}.tcc-student-name{font-size:21px}}

/* Course.php-style instructor lesson modules */
.tcc-lesson-timeline{margin-top:16px;border-radius:20px;border:1px solid rgba(15,23,42,.07);background:#fff;overflow:hidden}
.tcc-lesson-timeline-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(15,23,42,.06);background:linear-gradient(180deg,#ffffff 0%,#fbfdff 100%)}
.tcc-lesson-timeline-title{font-size:18px;line-height:1.1;font-weight:900;color:#102845;letter-spacing:-.03em}.tcc-lesson-timeline-sub{margin-top:5px;font-size:12px;color:#64748b;line-height:1.4}
.tcc-module-stack{display:flex;flex-direction:column;gap:12px;padding:14px;background:#fbfdff}.tcc-module-card{border:1px solid rgba(15,23,42,.07);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 20px rgba(15,23,42,.04)}.tcc-module-card summary{list-style:none;cursor:pointer;padding:14px 16px;background:#fff}.tcc-module-card summary::-webkit-details-marker{display:none}.tcc-module-head{display:grid;grid-template-columns:52px minmax(0,1fr) 140px 140px;gap:12px;align-items:center}.tcc-module-badge{width:42px;height:42px;border-radius:999px;background:linear-gradient(135deg,#081c33 0%,#11345d 100%);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px}.tcc-module-label{font-size:10px;color:#5f718d;margin-bottom:4px;font-weight:900;text-transform:uppercase;letter-spacing:.13em}.tcc-module-title{font-size:19px;font-weight:900;color:#152235;line-height:1.12;letter-spacing:-.02em;word-break:break-word}.tcc-module-mini{font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.tcc-module-value{margin-top:4px;font-size:18px;font-weight:900;color:#102845;line-height:1}.tcc-module-body{border-top:1px solid rgba(15,23,42,.06);background:#fff}
.tcc-instructor-lesson-wrap{overflow-x:auto}.tcc-instructor-lesson-table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:1080px}.tcc-instructor-lesson-table th{padding:11px 8px;border-bottom:1px solid rgba(15,23,42,.07);font-size:10px;text-transform:uppercase;letter-spacing:.13em;color:#60718b;font-weight:900;text-align:left;white-space:nowrap;background:#fbfdff}.tcc-instructor-lesson-table td{padding:10px 8px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:middle;text-align:left}.tcc-instructor-lesson-table tr.tcc-lesson-row{cursor:pointer;transition:background .12s ease}.tcc-instructor-lesson-table tr.tcc-lesson-row:hover td{background:#f8fbfd}.tcc-instructor-lesson-table th:first-child,.tcc-instructor-lesson-table td:first-child{padding-left:15px}.tcc-instructor-lesson-table th:last-child,.tcc-instructor-lesson-table td:last-child{padding-right:15px}
.tcc-lesson-main{display:flex;gap:8px;align-items:flex-start;min-width:0}.tcc-lesson-num{flex:0 0 auto;color:#3b4f68;font-weight:900;font-size:12px;line-height:1.25;min-width:18px;text-align:right}.tcc-lesson-name{font-size:13px;font-weight:900;color:#152235;line-height:1.25;word-break:break-word}.tcc-date-stack{display:flex;flex-direction:column;gap:5px;min-width:0}.tcc-date-line{font-size:11px;color:#152235;line-height:1.2;font-weight:800;white-space:normal}.tcc-date-line span{display:inline-block;min-width:56px;color:#64748b;font-weight:900;text-transform:uppercase;font-size:9px;letter-spacing:.08em}.tcc-small-grey{font-size:10px;color:#64748b;line-height:1.25;font-weight:700}.tcc-extension-ok{font-size:10px;color:#166534;line-height:1.25;font-weight:900}.tcc-extension-warn{font-size:10px;color:#991b1b;line-height:1.25;font-weight:900}.tcc-delta{font-size:10px;line-height:1.25;font-weight:900;margin-top:4px}.tcc-delta.ok{color:#166534}.tcc-delta.danger{color:#991b1b}.tcc-delta.neutral{color:#64748b}.tcc-delta.warn{color:#92400e}
.tcc-quality-wrap{min-width:0}.tcc-quality-top{font-size:12px;font-weight:900;color:#102845;line-height:1.2}.tcc-quality-bar{width:100%;height:7px;border-radius:999px;overflow:hidden;background:#e7edf4;margin-top:5px}.tcc-quality-bar span{display:block;height:7px;border-radius:999px;background:linear-gradient(90deg,#64748b 0%,#94a3b8 100%)}.tcc-quality-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}.tcc-quality-bar span.warn{background:linear-gradient(90deg,#b45309 0%,#f59e0b 100%)}.tcc-quality-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}.tcc-quality-bar span.info{background:linear-gradient(90deg,#1d4f91 0%,#3b82f6 100%)}
.tcc-score-pill{display:inline-flex;align-items:center;justify-content:center;min-width:34px;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:900;border:1px solid transparent}.tcc-score-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}.tcc-score-pill.danger{background:#fee2e2;border-color:#fca5a5;color:#991b1b}.tcc-score-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}.tcc-score-pill.neutral{background:#edf2f7;border-color:#d7dee9;color:#475569}.tcc-count-mini{display:inline-flex;align-items:center;justify-content:center;min-width:34px;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:900;background:#edf4ff;color:#1d4f91;border:1px solid #d3e3ff}.tcc-count-mini.warn{background:#fff7ed;color:#92400e;border-color:#fed7aa}.tcc-count-mini.neutral{background:#edf2f7;color:#475569;border-color:#d7dee9}
.tcc-lesson-detail-row td{padding:0!important;background:#fbfdff!important}.tcc-lesson-detail{display:none;padding:14px 16px;border-top:1px solid rgba(15,23,42,.05);background:#fbfdff}.tcc-lesson-detail.open{display:block}
.tcc-detail-btn{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border-radius:10px;padding:0 10px;font-size:11px;font-weight:900;text-decoration:none;border:1px solid rgba(15,23,42,.08);background:#f1f5f9;color:#334155;cursor:pointer}.tcc-detail-btn.primary{background:#12355f;color:#fff;border-color:#12355f}
@media (max-width:900px){.tcc-module-head{grid-template-columns:46px 1fr}.tcc-module-mini,.tcc-module-value{display:none}.tcc-instructor-lesson-table{min-width:980px}}

/* Modal detail styles */
.tcc-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tcc-modal-section{border:1px solid rgba(15,23,42,.08);border-radius:16px;background:#fbfdff;padding:14px;min-width:0}.tcc-modal-section.full{grid-column:1/-1}.tcc-modal-section-title{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:#64748b;margin-bottom:8px}.tcc-modal-readable{font-size:13px;line-height:1.55;color:#102845;max-height:300px;overflow:auto;white-space:pre-wrap;background:#fff;border:1px solid rgba(15,23,42,.06);border-radius:12px;padding:12px}.tcc-modal-muted{font-size:12px;color:#64748b;line-height:1.45}.tcc-modal-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid rgba(15,23,42,.06);font-size:13px}.tcc-modal-row:last-child{border-bottom:0}.tcc-modal-label{color:#64748b;font-weight:800}.tcc-modal-value{color:#102845;font-weight:800;text-align:right}.tcc-attempt-card{border:1px solid rgba(15,23,42,.08);border-radius:16px;background:#fff;margin-bottom:12px;overflow:hidden}.tcc-attempt-head{display:flex;justify-content:space-between;gap:12px;padding:12px 14px;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.06)}.tcc-attempt-title{font-weight:900;color:#102845}.tcc-attempt-meta{font-size:12px;color:#64748b;margin-top:3px}.tcc-question-list{padding:10px 14px;display:flex;flex-direction:column;gap:10px}.tcc-question-card{border:1px solid rgba(15,23,42,.06);border-radius:14px;padding:11px;background:#fbfdff}.tcc-question-prompt{font-weight:900;color:#102845;font-size:13px;line-height:1.4}.tcc-question-answer{margin-top:7px;font-size:13px;color:#334155;line-height:1.45;white-space:pre-wrap}.tcc-audio{margin-top:8px;width:100%}.tcc-ai-placeholder{border:1px dashed rgba(15,23,42,.18);border-radius:14px;background:#fff;padding:12px;color:#64748b;font-size:13px;line-height:1.5}.tcc-intervention-list{display:flex;flex-direction:column;gap:10px}.tcc-intervention-item{border:1px solid rgba(15,23,42,.07);border-radius:14px;background:#fff;padding:11px}.tcc-intervention-title{font-size:13px;font-weight:900;color:#102845}.tcc-intervention-meta{margin-top:4px;font-size:12px;color:#64748b}.tcc-json-mini{margin-top:8px;white-space:pre-wrap;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:10px;font-size:11px;max-height:180px;overflow:auto}@media(max-width:800px){.tcc-modal-grid{grid-template-columns:1fr}.tcc-modal-card{width:98vw}.tcc-modal-section.full{grid-column:auto}}

/* Inline stacked lesson details */
.tcc-inline-detail-stack{display:flex;flex-direction:column;gap:14px}
.tcc-inline-section{border:1px solid rgba(15,23,42,.08);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 18px rgba(15,23,42,.035)}
.tcc-inline-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:13px 15px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border-bottom:1px solid rgba(15,23,42,.06)}
.tcc-inline-section-title{font-size:14px;font-weight:900;color:#102845;line-height:1.15;letter-spacing:-.015em}
.tcc-inline-section-sub{margin-top:4px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}
.tcc-inline-section-body{padding:14px 15px}
.tcc-inline-summary-text{max-height:260px;overflow:auto;white-space:pre-wrap;font-size:13px;line-height:1.55;color:#102845;background:#fbfdff;border:1px solid rgba(15,23,42,.06);border-radius:14px;padding:12px}
.tcc-inline-ai-box{margin-top:10px;border:1px dashed rgba(15,23,42,.18);border-radius:14px;background:#fff;padding:12px;color:#64748b;font-size:12px;line-height:1.5}
.tcc-inline-attempt{border:1px solid rgba(15,23,42,.07);border-radius:15px;background:#fbfdff;margin-bottom:10px;overflow:hidden}
.tcc-inline-attempt-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:11px 12px;background:#fff;border-bottom:1px solid rgba(15,23,42,.06)}
.tcc-inline-attempt-title{font-size:13px;font-weight:900;color:#102845;line-height:1.25}
.tcc-inline-attempt-meta{margin-top:3px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}
.tcc-inline-question{padding:10px 12px;border-top:1px solid rgba(15,23,42,.05)}
.tcc-inline-question:first-child{border-top:0}
.tcc-inline-question-title{font-size:12px;font-weight:900;color:#102845;line-height:1.35}
.tcc-inline-question-answer{margin-top:6px;font-size:12px;color:#334155;line-height:1.45;white-space:pre-wrap}
.tcc-inline-intervention{border:1px solid rgba(15,23,42,.07);border-radius:14px;background:#fbfdff;padding:11px 12px;margin-bottom:9px}
.tcc-inline-intervention-title{font-size:12px;font-weight:900;color:#102845;line-height:1.3}
.tcc-inline-intervention-meta{margin-top:4px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}
.tcc-inline-loading{padding:13px;border:1px dashed rgba(15,23,42,.14);border-radius:14px;background:#fff;color:#64748b;font-size:13px}
.tcc-inline-error{padding:13px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#991b1b;font-size:13px}
.tcc-inline-empty{padding:13px;border:1px dashed rgba(15,23,42,.14);border-radius:14px;background:#fff;color:#64748b;font-size:13px}
.tcc-inline-mini-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
.tcc-inline-mini-card{border-radius:13px;background:#fbfdff;border:1px solid rgba(15,23,42,.06);padding:10px}
.tcc-inline-mini-label{font-size:9px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}
.tcc-inline-mini-value{margin-top:5px;font-size:13px;font-weight:900;color:#102845;line-height:1.2}
@media(max-width:800px){.tcc-inline-mini-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* Phase 2L: inline details polish */
.tcc-inline-section-head{background:linear-gradient(135deg,#0f2745 0%,#1d4f89 100%)!important;color:#fff!important}
.tcc-inline-section-title{color:#fff!important}
.tcc-inline-section-sub{color:rgba(255,255,255,.76)!important}
.tcc-inline-section-head .tcc-detail-btn.primary{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.28);color:#fff}
.tcc-review-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:900;border:1px solid transparent}
.tcc-review-pill.acceptable,.tcc-review-pill.ok{background:#dcfce7;color:#166534;border-color:#86efac}
.tcc-review-pill.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.tcc-review-pill.needs_revision,.tcc-review-pill.rejected,.tcc-review-pill.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.tcc-review-pill.neutral{background:#edf2f7;color:#475569;border-color:#d7dee9}
.tcc-review-score-row{display:flex;align-items:center;gap:10px}
.tcc-review-score-bar{height:9px;flex:1;border-radius:999px;background:#e7edf4;overflow:hidden;min-width:80px}
.tcc-review-score-bar span{display:block;height:100%;border-radius:999px}
.tcc-review-score-bar span.ok{background:linear-gradient(90deg,#166534 0%,#22c55e 100%)}
.tcc-review-score-bar span.warn{background:linear-gradient(90deg,#d97706 0%,#f59e0b 100%)}
.tcc-review-score-bar span.danger{background:linear-gradient(90deg,#b91c1c 0%,#ef4444 100%)}
.tcc-review-score-value{font-size:12px;font-weight:900;color:#102845;min-width:42px;text-align:right}
.tcc-summary-paper{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:0 8px 20px rgba(15,23,42,.045);padding:18px 20px;max-height:360px;overflow:auto}
.tcc-summary-paper.nb-content{margin-top:0;color:#233246;line-height:1.82;font-size:15px;white-space:normal}
.tcc-summary-paper.nb-content p{margin:0 0 12px 0}
.tcc-summary-paper.nb-content ul,.tcc-summary-paper.nb-content ol{margin:0 0 12px 22px}
.tcc-summary-paper.nb-content li{margin:0 0 6px 0}
.tcc-summary-paper.nb-content b,.tcc-summary-paper.nb-content strong{color:#16263c}
.tcc-summary-paper.nb-content mark{background:#fff59d;color:inherit;padding:0 .1em;border-radius:3px}
.tcc-ai-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}
.tcc-ai-signal{border:1px solid rgba(15,23,42,.08);border-radius:14px;background:#fbfdff;padding:10px;min-width:0}
.tcc-ai-signal-label{font-size:9px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}
.tcc-ai-signal-value{margin-top:6px;font-size:13px;font-weight:900;color:#102845;line-height:1.2}
.tcc-ai-signal.ok{background:#f0fdf4;border-color:#bbf7d0}.tcc-ai-signal.ok .tcc-ai-signal-value{color:#166534}
.tcc-ai-signal.warn{background:#fffbeb;border-color:#fde68a}.tcc-ai-signal.warn .tcc-ai-signal-value{color:#92400e}
.tcc-ai-signal.danger{background:#fef2f2;border-color:#fecaca}.tcc-ai-signal.danger .tcc-ai-signal-value{color:#991b1b}
.tcc-chat-thread{display:flex;flex-direction:column;gap:10px;padding:10px 12px}
.tcc-chat-bubble{max-width:78%;border-radius:18px;padding:11px 13px;font-size:13px;line-height:1.45;box-shadow:0 6px 14px rgba(15,23,42,.06)}
.tcc-chat-bubble.ai{align-self:flex-start;background:#eef2f7;color:#111827;border:1px solid rgba(15,23,42,.06);border-bottom-left-radius:6px}
.tcc-chat-bubble.student{align-self:flex-end;background:#dbeafe;color:#0f172a;border:1px solid #bfdbfe;border-bottom-right-radius:6px;cursor:pointer}
.tcc-chat-bubble.student:hover{filter:brightness(.99);box-shadow:0 9px 18px rgba(37,99,235,.16)}
.tcc-chat-label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;opacity:.72;margin-bottom:5px}
.tcc-chat-score{margin-top:7px;font-size:11px;font-weight:900;opacity:.9}
.tcc-intervention-clickable{cursor:pointer;transition:transform .08s ease,box-shadow .12s ease}
.tcc-intervention-clickable:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(15,23,42,.08)}
.tcc-chat-play{margin-top:8px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 9px;background:#fff;color:#1d4ed8;border:1px solid #93c5fd;font-size:11px;font-weight:900}
.tcc-summary-large-btn{margin-top:10px}
.tcc-summary-safe-frame{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:0 8px 20px rgba(15,23,42,.045);padding:18px 20px;max-height:430px;overflow:auto;color:#233246;line-height:1.82;font-size:15px}
.tcc-summary-safe-frame p{margin:0 0 12px 0}.tcc-summary-safe-frame ul,.tcc-summary-safe-frame ol{margin:0 0 12px 22px}.tcc-summary-safe-frame li{margin:0 0 6px 0}.tcc-summary-safe-frame b,.tcc-summary-safe-frame strong{color:#16263c}
@media(max-width:800px){.tcc-ai-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tcc-chat-bubble{max-width:92%}}

.tcc-ai-live-panel{margin-top:12px;border:1px solid rgba(15,23,42,.08);border-radius:18px;background:#ffffff;overflow:hidden}
.tcc-ai-live-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:13px 15px;background:#f8fafc;border-bottom:1px solid rgba(15,23,42,.06)}
.tcc-ai-live-title{font-size:13px;font-weight:900;color:#102845;line-height:1.2}
.tcc-ai-live-sub{margin-top:4px;font-size:11px;color:#64748b;line-height:1.35;font-weight:700}
.tcc-ai-live-body{padding:13px 15px}
.tcc-ai-action-btn{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border-radius:10px;padding:0 11px;font-size:11px;font-weight:900;border:1px solid rgba(15,23,42,.08);background:#12355f;color:#fff;cursor:pointer;white-space:nowrap}
.tcc-ai-action-btn[disabled]{opacity:.55;cursor:not-allowed}
.tcc-ai-result-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.tcc-ai-result-card{border:1px solid rgba(15,23,42,.08);border-radius:15px;background:#fbfdff;padding:11px;min-width:0}
.tcc-ai-result-card.ok{background:#f0fdf4;border-color:#bbf7d0}
.tcc-ai-result-card.warn{background:#fffbeb;border-color:#fde68a}
.tcc-ai-result-card.danger{background:#fef2f2;border-color:#fecaca}
.tcc-ai-result-label{font-size:9px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b}
.tcc-ai-result-value{margin-top:6px;font-size:14px;font-weight:900;color:#102845;line-height:1.2}
.tcc-ai-result-card.ok .tcc-ai-result-value{color:#166534}
.tcc-ai-result-card.warn .tcc-ai-result-value{color:#92400e}
.tcc-ai-result-card.danger .tcc-ai-result-value{color:#991b1b}
.tcc-ai-take-box{margin-top:10px;border-radius:15px;background:#fbfdff;border:1px solid rgba(15,23,42,.07);padding:12px;font-size:13px;color:#334155;line-height:1.5}
.tcc-ai-list-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}
.tcc-ai-list-box{border-radius:15px;border:1px solid rgba(15,23,42,.07);background:#fff;padding:12px}
.tcc-ai-list-title{font-size:11px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:#64748b;margin-bottom:8px}
.tcc-ai-list-box ul{margin:0 0 0 18px;padding:0}
.tcc-ai-list-box li{font-size:12px;color:#334155;line-height:1.45;margin-bottom:5px}
.tcc-ai-error-box{padding:12px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-size:13px;line-height:1.45}
.tcc-ai-loading-box{padding:12px;border-radius:14px;border:1px dashed rgba(15,23,42,.16);background:#fbfdff;color:#64748b;font-size:13px}
@media(max-width:900px){.tcc-ai-result-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tcc-ai-list-grid{grid-template-columns:1fr}}
@media(max-width:520px){.tcc-ai-result-grid{grid-template-columns:1fr}}
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
                <div class="tcc-section-sub">Student position and risk state across the theory program. Tap an avatar or use the selector when students overlap.</div>
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
            <span class="tcc-legend-pill"><span class="tcc-dot orange"></span>At risk</span>
            <span class="tcc-legend-pill"><span class="tcc-dot red"></span>Blocked</span>
            <span class="tcc-legend-pill"><span class="tcc-dot blue"></span>Action pending</span>
            <span class="tcc-legend-pill"><span class="tcc-dot purple"></span>System watch</span>
        </div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Student Deep Dive</h2>
                <div class="tcc-section-sub">Snapshot of the selected student's progress, friction points, and comparative indicators.</div>
            </div>
            <div id="studentPanelCount" class="tcc-count-pill">Select student</div>
        </div>
        <div id="studentPanel" class="tcc-student-placeholder">Tap a student avatar in the Cohort Radar, choose a student from the selector, or tap a student in the Action Queue to open the student deep dive here.</div>
    </section>

    <section class="tcc-card tcc-section">
        <div class="tcc-section-head">
            <div>
                <h2 class="tcc-section-title">Needs My Action</h2>
                <div class="tcc-section-sub">Instructor-facing queue of open required actions. This remains last so the page opens with cohort context first.</div>
            </div>
            <div id="queueCount" class="tcc-count-pill">—</div>
        </div>
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

    let cohortId = 0;
    let selectedStudentId = 0;
    let cohortStudents = [];

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            }[c];
        });
    }

    function api(action, params) {
        params = params || {};
        params.action = action;

        return fetch('/instructor/api/theory_control_center_api.php?' + new URLSearchParams(params), {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        });
    }

	function repairApi(payload) {
    return fetch('/instructor/api/theory_control_center_repair_execute.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload || {})
    }).then(function (r) {
        return r.json();
    });
}
	
	
    function openTccModal(title, bodyHtml) {
        document.getElementById('tccModalTitle').textContent = title || 'Diagnostic';
        document.getElementById('tccModalBody').innerHTML = bodyHtml || '';

        const o = document.getElementById('tccModalOverlay');
        o.classList.add('open');
        o.setAttribute('aria-hidden', 'false');
    }

    function closeTccModal() {
        const o = document.getElementById('tccModalOverlay');
        o.classList.remove('open');
        o.setAttribute('aria-hidden', 'true');
    }

    function showError(id, msg) {
        const el = document.getElementById(id);
        if (el) {
            el.innerHTML = '<div class="tcc-error">' + escapeHtml(msg) + '</div>';
        }
    }

    function clampPct(v) {
        v = parseFloat(v);
        if (isNaN(v)) v = 0;
        if (v < 0) v = 0;
        if (v > 100) v = 100;
        return Math.round(v);
    }

    function firstName(name) {
        return String(name || 'Student').trim().split(/\s+/)[0] || 'Student';
    }

    function photoPath(o) {
        let path = String((o && (o.photo_path || o.avatar_url || o.photoPath || o.image_url)) || '').trim();
        if (path === '') return '';
        if (path.indexOf('http://') === 0 || path.indexOf('https://') === 0 || path.indexOf('/') === 0) return path;
        return '/' + path.replace(/^\/+/, '');
    }

    function avatarHtml(o, cls) {
        const path = photoPath(o);
        const initials = escapeHtml((o && o.avatar_initials) || 'S');

        if (path !== '') {
            return '<div class="' + cls + '"><img src="' + escapeHtml(path) + '" alt="' + escapeHtml((o && o.name) || 'Student') + '"></div>';
        }

        return '<div class="' + cls + '">' + initials + '</div>';
    }

    function radarColor(s) {
        if (s.state === 'blocked') return 'red';
        if (s.pending_action_count && parseInt(s.pending_action_count, 10) > 0) return 'blue';
        if (s.state === 'at_risk') return 'orange';
        return 'green';
    }

    function blockerReviewHref(issue) {
        if (issue.action_url) return String(issue.action_url);

        if (issue.token) {
            if (issue.type === 'instructor_approval') {
                return '/instructor/instructor_approval.php?token=' + encodeURIComponent(issue.token);
            }

            return '/student/remediation_action.php?token=' + encodeURIComponent(issue.token);
        }

        return '';
    }

    function cohortTimeZone() {
        return window.tccCohortTimezone || 'UTC';
    }

    function parseUtcDate(v) {
        if (!v) return null;

        const raw = String(v).trim();
        if (raw === '') return null;

        const iso = raw.indexOf('T') >= 0 ? raw : (raw.replace(' ', 'T') + 'Z');
        const d = new Date(iso);

        return isNaN(d.getTime()) ? null : d;
    }

    function partsInCohortTime(v) {
        const d = parseUtcDate(v);
        if (!d) return null;

        try {
            const fmt = new Intl.DateTimeFormat('en-US', {
                timeZone: cohortTimeZone(),
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });

            const parts = {};
            fmt.formatToParts(d).forEach(function (p) {
                parts[p.type] = p.value;
            });

            return parts;
        } catch (e) {
            return null;
        }
    }

    function niceDate(v) {
        const p = partsInCohortTime(v);
        if (!p) return v ? String(v).slice(0, 16) : '—';
        return p.weekday + ' ' + p.month + ' ' + p.day + ', ' + p.year;
    }

    function niceDateTime(v) {
        const p = partsInCohortTime(v);
        if (!p) return v ? String(v).slice(0, 16) : '—';
        return p.weekday + ' ' + p.month + ' ' + p.day + ', ' + p.year + ' ' + p.hour + ':' + p.minute;
    }

    function niceTime(v) {
        const p = partsInCohortTime(v);
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
        let html = '';

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

        const tpl = document.createElement('template');
        tpl.innerHTML = html;

        const allowedTags = {
            P: 1,
            BR: 1,
            UL: 1,
            OL: 1,
            LI: 1,
            STRONG: 1,
            B: 1,
            EM: 1,
            I: 1,
            U: 1,
            H1: 1,
            H2: 1,
            H3: 1,
            H4: 1,
            BLOCKQUOTE: 1,
            SPAN: 1,
            DIV: 1,
            MARK: 1
        };

        const walker = document.createTreeWalker(tpl.content, NodeFilter.SHOW_ELEMENT, null);
        const remove = [];

        while (walker.nextNode()) {
            const el = walker.currentNode;

            if (!allowedTags[el.tagName]) {
                remove.push(el);
                continue;
            }

            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                const n = a.name.toLowerCase();
                if (n !== 'class') el.removeAttribute(a.name);
            });
        }

        remove.forEach(function (el) {
            const text = document.createTextNode(el.textContent || '');
            if (el.parentNode) el.parentNode.replaceChild(text, el);
        });

        return tpl.innerHTML;
    }

    function rawSummaryHtml(s) {
        const html = String((s && (s.summary_html || s.summaryHtml)) || '').trim();
        if (html !== '') return sanitizeSummaryHtml(html);

        const plain = String((s && (s.summary_plain || s.summary_text)) || '').trim();
        return plain !== '' ? escapeHtml(plain).replace(/\n/g, '<br>') : 'No student summary text found.';
    }

    function cacheSummaryHtml(title, htmlContent) {
        window.tccSummaryCache = window.tccSummaryCache || {};

        const key = 's_' + Date.now() + '_' + Math.floor(Math.random() * 1000000);
        window.tccSummaryCache[key] = {
            title: String(title || 'Lesson Summary'),
            html: String(htmlContent || '')
        };

        return key;
    }

    function openSummaryLargeModalById(key) {
        const item = (window.tccSummaryCache || {})[key] || {};
        openSummaryLargeModal(item.title || 'Lesson Summary', item.html || '');
    }

    function reviewStatusPill(status) {
        const clean = String(status || 'neutral').toLowerCase();
        return '<span class="tcc-review-pill ' + escapeHtml(clean) + '">' + escapeHtml(prettyStatus(clean)) + '</span>';
    }

    function reviewScoreBar(score) {
        const n = (score !== null && score !== undefined && score !== '') ? clampPct(score) : 0;
        const cls = n >= 85 ? 'ok' : (n >= 70 ? 'warn' : 'danger');

        return '<div class="tcc-review-score-row"><div class="tcc-review-score-bar"><span class="' + cls + '" style="width:' + n + '%;"></span></div><div class="tcc-review-score-value">' + (score !== null && score !== undefined && score !== '' ? escapeHtml(score) + '%' : '—') + '</div></div>';
    }

    function aiSignalClass(value) {
        const s = String(value || '').toLowerCase();

        if (s.indexOf('high') >= 0 || s.indexOf('likely') >= 0 || s.indexOf('weak') >= 0 || s.indexOf('poor') >= 0) return 'danger';
        if (s.indexOf('medium') >= 0 || s.indexOf('possible') >= 0 || s.indexOf('developing') >= 0 || s.indexOf('adequate') >= 0) return 'warn';
        if (s.indexOf('low') >= 0 || s.indexOf('unlikely') >= 0 || s.indexOf('strong') >= 0 || s.indexOf('excellent') >= 0) return 'ok';

        return '';
    }

    function aiList(items) {
        items = Array.isArray(items) ? items : [];

        if (!items.length) return '<div class="tcc-modal-muted">No items generated yet.</div>';

        let html = '<ul>';
        items.slice(0, 6).forEach(function (item) {
            html += '<li>' + escapeHtml(item) + '</li>';
        });
        html += '</ul>';

        return html;
    }

    function renderAiAnalysisObject(ai) {
        ai = ai || {};

        const copy = ai.copy_paste_likelihood || 'Not generated';
        const tool = ai.ai_tool_likelihood || 'Not generated';
        const sim = ai.highest_similarity || 'Not generated';
        const simStudent = ai.highest_similarity_student || '—';
        const simPct = (ai.highest_similarity_pct !== undefined && ai.highest_similarity_pct !== null) ? String(ai.highest_similarity_pct) + '%' : '—';
        const understandingLabel = ai.deep_understanding_label || ai.deep_understanding || ai.understanding || 'Not generated';
        const understandingScore = (ai.deep_understanding_score !== undefined && ai.deep_understanding_score !== null) ? String(ai.deep_understanding_score) + '%' : '—';

        let html = '<div class="tcc-ai-result-grid">';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(copy) + '"><div class="tcc-ai-result-label">Copy/Paste</div><div class="tcc-ai-result-value">' + escapeHtml(copy) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(tool) + '"><div class="tcc-ai-result-label">AI Tool Use</div><div class="tcc-ai-result-value">' + escapeHtml(tool) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(sim) + '"><div class="tcc-ai-result-label">Similarity</div><div class="tcc-ai-result-value">' + escapeHtml(sim) + ' · ' + escapeHtml(simPct) + '</div><div class="tcc-modal-muted" style="margin-top:5px;">' + escapeHtml(simStudent) + '</div></div>';
        html += '<div class="tcc-ai-result-card ' + aiSignalClass(understandingLabel) + '"><div class="tcc-ai-result-label">Understanding</div><div class="tcc-ai-result-value">' + escapeHtml(understandingLabel) + ' · ' + escapeHtml(understandingScore) + '</div></div>';
        html += '</div>';

        html += '<div class="tcc-ai-take-box"><strong>Instructor quick take:</strong><br>' + escapeHtml(ai.instructor_quick_take || ai.quality_feedback || 'No AI analysis generated yet.') + '</div>';

        html += '<div class="tcc-ai-list-grid">';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">Strong Points</div>' + aiList(ai.substantially_good) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">Weak Points</div>' + aiList(ai.substantially_weak) + '</div>';
        html += '<div class="tcc-ai-list-box"><div class="tcc-ai-list-title">Suggestions</div>' + aiList(ai.improvement_suggestions || ai.suggestions) + '</div>';
        html += '</div>';

        if (ai.student_safe_feedback) {
            html += '<div class="tcc-ai-take-box"><strong>Student-safe feedback:</strong><br>' + escapeHtml(ai.student_safe_feedback) + '</div>';
        }

        return html;
    }

    function renderAiInterpretation(ai, studentId, lessonId) {
        ai = ai || {};

        const hasGenerated = (ai.analysis_status === 'generated' || ai.copy_paste_likelihood || ai.ai_tool_likelihood || ai.deep_understanding_score || ai.deep_understanding);
        const panelId = 'aiPanel_' + parseInt(studentId || 0, 10) + '_' + parseInt(lessonId || 0, 10);
        const btnId = 'aiBtn_' + parseInt(studentId || 0, 10) + '_' + parseInt(lessonId || 0, 10);

        const body = hasGenerated
            ? renderAiAnalysisObject(ai)
            : '<div class="tcc-ai-loading-box">No AI analysis generated yet. Click “Generate AI Analysis” to create an instructor advisory review for this summary.</div>';

        return '<div id="' + panelId + '" class="tcc-ai-live-panel">' +
            '<div class="tcc-ai-live-head">' +
                '<div><div class="tcc-ai-live-title">AI Summary Analysis</div><div class="tcc-ai-live-sub">Advisory only. Does not change progression status or canonical records.</div></div>' +
                '<button id="' + btnId + '" type="button" class="tcc-ai-action-btn" data-student-id="' + parseInt(studentId || 0, 10) + '" data-lesson-id="' + parseInt(lessonId || 0, 10) + '" data-panel-id="' + escapeHtml(panelId) + '" data-btn-id="' + escapeHtml(btnId) + '" onclick="window.generateAiSummaryAnalysisFromButton(this)">Generate AI Analysis</button>' +
            '</div>' +
            '<div class="tcc-ai-live-body">' + body + '</div>' +
        '</div>';
    }

    function generateAiSummaryAnalysis(studentId, lessonId, panelId, btnId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;

        const panel = document.getElementById(panelId);
        const btn = document.getElementById(btnId);

        if (!panel || !studentId || !lessonId || !cohortId) {
            openTccModal('AI Summary Analysis', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }

        const body = panel.querySelector('.tcc-ai-live-body');

        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Analyzing…';
        }

        if (body) {
            body.innerHTML = '<div class="tcc-ai-loading-box">Generating AI analysis. This is advisory only and will not change student progression state…</div>';
        }

        api('ai_summary_analysis', {
            cohort_id: cohortId,
            student_id: studentId,
            lesson_id: lessonId
        }).then(function (resp) {
            if (!resp.ok) {
                if (body) {
                    body.innerHTML = '<div class="tcc-ai-error-box">' + escapeHtml(resp.message || resp.error || 'AI analysis failed.') + '</div>';
                }
                return;
            }

            if (body) {
                body.innerHTML = renderAiAnalysisObject(resp.analysis || {});
            }
        }).catch(function () {
            if (body) {
                body.innerHTML = '<div class="tcc-ai-error-box">Unable to generate AI analysis.</div>';
            }
        }).finally(function () {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Regenerate AI Analysis';
            }
        });
    }

	function generateAiSummaryAnalysisFromButton(btn) {

    generateAiSummaryAnalysis(

        parseInt(btn.getAttribute('data-student-id') || '0', 10),

        parseInt(btn.getAttribute('data-lesson-id') || '0', 10),

        btn.getAttribute('data-panel-id') || '',

        btn.getAttribute('data-btn-id') || ''

    );

}
	
	
    function openSummaryLargeModal(title, htmlContent) {
        openTccModal(title || 'Lesson Summary', '<div class="tcc-summary-paper nb-content" style="max-height:70vh;">' + htmlContent + '</div>');
    }

    function openAnswerAudioModal(question, transcript, audioUrl, score) {
        let body = '<div class="tcc-modal-muted" style="margin-bottom:10px;">' + escapeHtml(question || 'Progress test answer') + '</div>';

        if (audioUrl) {
            body += '<audio class="tcc-audio" controls preload="none" src="' + escapeHtml(audioUrl) + '"></audio>';
        } else {
            body += '<div class="tcc-empty">No audio file is attached to this answer.</div>';
        }

        body += '<div class="tcc-modal-section full" style="margin-top:12px;"><div class="tcc-modal-section-title">Student Answer Transcript</div><div class="tcc-modal-readable">' + escapeHtml(transcript || '—') + '</div><div class="tcc-modal-muted" style="margin-top:8px;">Score: ' + escapeHtml(score || '—') + '</div></div>';

        openTccModal('Answer Audio + Transcript', body);
    }

    function openInterventionDetailModal(item) {
        item = item || {};

        const title = item.title || item.email_type || item.event_type || item.override_type || item.action_type || ('Intervention #' + (item.id || ''));
        openTccModal('Intervention Detail', '<div class="tcc-debug-meta">' + escapeHtml(title) + '</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(item, null, 2)) + '</pre>');
    }

    function openDebugReport(studentId, lessonId, issueType) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;

        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Debug Report', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }

        openTccModal('Debug Report', '<div class="tcc-loading">Generating diagnostic report…</div>');

        api('debug_report', {
            cohort_id: cohortId,
            student_id: studentId,
            lesson_id: lessonId,
            issue_type: issueType || 'manual_check'
        }).then(function (data) {
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

        api('system_watch', {
            cohort_id: cohortId,
            student_id: studentId
        }).then(function (data) {
            openTccModal('System Watch', '<pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>');
        }).catch(function () {
            openTccModal('System Watch', '<div class="tcc-error">Unable to load system watch.</div>');
        });
    }

    function openLessonSummary(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;

        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Lesson Summary', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }

        openTccModal('Lesson Summary', '<div class="tcc-loading">Loading lesson summary…</div>');

        api('lesson_summary_detail', {
            cohort_id: cohortId,
            student_id: studentId,
            lesson_id: lessonId
        }).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Lesson Summary', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load summary.') + '</div>');
                return;
            }

            const d = resp.data || {};
            const lesson = d.lesson || {};
            const s = d.summary || {};
            const ai = d.ai_interpretation || {};
            const title = lesson.lesson_title || 'Lesson Summary';
            const summaryHtml = rawSummaryHtml(s);

            const html = '<div class="tcc-modal-grid">' +
                '<div class="tcc-modal-section"><div class="tcc-modal-section-title">Review Status</div>' +
                    modalStatusRows([
                        ['Module', lesson.course_title || '—'],
                        ['Lesson', lesson.lesson_title || '—'],
                        ['Updated', niceDateTime(s.updated_at)]
                    ]) +
                    '<div style="margin-top:10px;">' + reviewStatusPill(s.review_status || 'neutral') + '</div>' +
                '</div>' +
                '<div class="tcc-modal-section"><div class="tcc-modal-section-title">Review Score</div>' + reviewScoreBar(s.review_score) + '</div>' +
                '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Student Summary</div><div class="tcc-summary-paper nb-content">' + summaryHtml + '</div></div>' +
                '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">AI Interpretation</div>' + renderAiInterpretation(ai, studentId, lessonId) + '</div>' +
                '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Instructor Feedback</div><div class="tcc-modal-readable">' + escapeHtml(s.review_feedback || s.review_notes_by_instructor || 'No instructor feedback recorded for this summary yet.') + '</div></div>' +
            '</div>';

            openTccModal(title, html);
        }).catch(function () {
            openTccModal('Lesson Summary', '<div class="tcc-error">Unable to load summary.</div>');
        });
    }

    function renderAttemptItems(items) {
        if (!items || !items.length) return '<div class="tcc-modal-muted">No answer-level items found for this attempt.</div>';

        let html = '<div class="tcc-chat-thread">';

        items.forEach(function (item, idx) {
            const score = formatScoreValue(item.score_points, item.max_points);
            const q = item.prompt || item.question_text || 'Question';
            const transcript = item.transcript_text || item.answer_text || item.student_answer || '—';
            const audio = item.audio_url || item.audio_path || item.answer_audio_url || item.recording_url || item.media_url || '';

            html += '<div class="tcc-chat-bubble ai"><strong>Question ' + (idx + 1) + ':</strong> ' + escapeHtml(q) + '</div>';
            html += '<div class="tcc-chat-bubble student" onclick="openAnswerAudioModal(' + jsArg(q) + ',' + jsArg(transcript) + ',' + jsArg(audio) + ',' + jsArg(score) + ')">' + escapeHtml(transcript) + '<div class="tcc-chat-score">Score: ' + escapeHtml(score) + '</div><span class="tcc-chat-play">▶ Play audio</span></div>';
        });

        html += '</div>';
        return html;
    }

    function openAttemptDetails(studentId, lessonId) {
        studentId = parseInt(studentId, 10) || selectedStudentId;
        lessonId = parseInt(lessonId, 10) || 0;

        if (!cohortId || !studentId || !lessonId) {
            openTccModal('Progress Test Details', '<div class="tcc-error">Missing cohort, student, or lesson id.</div>');
            return;
        }

        openTccModal('Progress Test Details', '<div class="tcc-loading">Loading progress test attempts…</div>');

        api('lesson_attempts_detail', {
            cohort_id: cohortId,
            student_id: studentId,
            lesson_id: lessonId
        }).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Progress Test Details', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load attempts.') + '</div>');
                return;
            }

            const d = resp.data || {};
            const lesson = d.lesson || {};
            const attempts = d.attempts || [];

            let html = '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">Lesson</div><div class="tcc-modal-muted">' + escapeHtml((lesson.course_title || 'Module') + ' · ' + (lesson.lesson_title || 'Lesson')) + '</div></div>';

            if (!attempts.length) {
                html += '<div class="tcc-empty">No progress test attempts found.</div>';
            }

            attempts.forEach(function (a) {
                const score = a.score_pct !== null && a.score_pct !== undefined ? a.score_pct + '%' : '—';
                html += '<div class="tcc-attempt-card"><div class="tcc-attempt-head"><div><div class="tcc-attempt-title">Attempt ' + escapeHtml(a.attempt || '—') + ' · ' + escapeHtml(a.formal_result_code || a.status || '') + '</div><div class="tcc-attempt-meta">Started: ' + escapeHtml(niceDate(a.started_at)) + ' · Completed: ' + escapeHtml(niceDate(a.completed_at)) + '</div></div><span class="tcc-score-pill ' + (parseInt(a.pass_gate_met, 10) === 1 ? 'ok' : 'danger') + '">' + escapeHtml(score) + '</span></div>' + renderAttemptItems(a.items || []) + '</div>';
            });

            openTccModal('Progress Test Details', html);
        }).catch(function () {
            openTccModal('Progress Test Details', '<div class="tcc-error">Unable to load attempts.</div>');
        });
    }

    function serializeForOnclick(value) {
        return escapeHtml(JSON.stringify(value)).replace(/"/g, '&quot;');
    }

    function interventionBlock(title, items) {
        let html = '<div class="tcc-modal-section full"><div class="tcc-modal-section-title">' + escapeHtml(title) + ' (' + (items ? items.length : 0) + ')</div>';

        if (!items || !items.length) {
            html += '<div class="tcc-modal-muted">No records found.</div></div>';
            return html;
        }

        html += '<div class="tcc-intervention-list">';

        items.forEach(function (item) {
            const label = item.title || item.email_type || item.event_type || item.override_type || item.action_type || ('Record #' + (item.id || ''));
            const meta = [
                item.status || item.sent_status || '',
                item.created_at ? niceDateTime(item.created_at) : '',
                item.sent_at ? ('Sent ' + niceDateTime(item.sent_at)) : ''
            ].filter(Boolean).join(' · ');

            html += '<div class="tcc-intervention-item tcc-intervention-clickable" onclick="openInterventionDetailModal(' + serializeForOnclick(item) + ')"><div class="tcc-intervention-title">' + escapeHtml(label) + '</div><div class="tcc-intervention-meta">' + escapeHtml(meta || '—') + '</div></div>';
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

        api('lesson_interventions_detail', {
            cohort_id: cohortId,
            student_id: studentId,
            lesson_id: lessonId
        }).then(function (resp) {
            if (!resp.ok) {
                openTccModal('Interventions', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Unable to load interventions.') + '</div>');
                return;
            }

            const d = resp.data || {};
            const lesson = d.lesson || {};
            const subtitle = lessonId > 0 ? ((lesson.course_title || 'Module') + ' · ' + (lesson.lesson_title || 'Lesson')) : 'Entire program';

            let html = '<div class="tcc-modal-muted" style="margin-bottom:12px;">' + escapeHtml(subtitle) + '</div>';
            html += interventionBlock('Required Actions', d.required_actions || []);
            html += interventionBlock('Deadline Overrides / Extensions', d.deadline_overrides || []);
            html += interventionBlock('Email Trace', d.emails || []);
            html += interventionBlock('Progression Events', d.events || []);

            openTccModal('Interventions', html);
        }).catch(function () {
            openTccModal('Interventions', '<div class="tcc-error">Unable to load interventions.</div>');
        });
    }

    function barClass(value, average, higherIsBetter) {
        const v = parseFloat(value);
        const a = parseFloat(average);

        if (isNaN(v)) return 'warn';

        if (isNaN(a)) {
            return higherIsBetter ? (v >= 75 ? 'ok' : (v >= 70 ? 'warn' : 'danger')) : (v === 0 ? 'ok' : 'warn');
        }

        if (higherIsBetter) {
            if (v >= a && v >= 75) return 'ok';
            if (v >= 70) return 'warn';
            return 'danger';
        }

        if (v <= a) return 'ok';
        if (v <= a * 1.5 + 0.5) return 'warn';
        return 'danger';
    }

    function metricPct(value, average, higherIsBetter) {
        const v = parseFloat(value);
        const a = parseFloat(average);

        if (isNaN(v)) return 0;
        if (higherIsBetter) return clampPct(v);

        const max = Math.max(v, a * 2, 1);
        return clampPct((v / max) * 100);
    }

    function metricTile(title, value, barPct, barCls, sub) {
        return '<div class="tcc-snapshot-tile"><div class="tcc-snapshot-label">' + escapeHtml(title) + '</div><div class="tcc-snapshot-value">' + escapeHtml(value) + '</div><div class="tcc-mini-bar"><span class="' + escapeHtml(barCls) + '" style="width:' + clampPct(barPct) + '%;"></span></div><div class="tcc-snapshot-sub">' + escapeHtml(sub) + '</div></div>';
    }

    function loadCohorts() {
        api('cohort_overview').then(function (data) {
            const select = document.getElementById('cohortSelect');
            select.innerHTML = '';

            if (!data.ok || !data.cohorts || !data.cohorts.length) {
                select.innerHTML = '<option value="0">No cohorts available</option>';
                return;
            }

            data.cohorts.forEach(function (c) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;

                if (c.timezone || c.cohort_timezone) {
                    opt.setAttribute('data-timezone', c.timezone || c.cohort_timezone);
                }

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
        document.getElementById('studentPanelCount').textContent = 'Select student';
        document.getElementById('studentPanel').className = 'tcc-student-placeholder';
        document.getElementById('studentPanel').innerHTML = 'Tap a student avatar in the Cohort Radar, choose a student from the selector, or tap a student in the Action Queue to open the student deep dive here.';

        loadOverview();
        loadQueue();
    }

    function loadOverview() {
        document.getElementById('healthStrip').innerHTML = '<div class="tcc-loading">Loading cohort health…</div>';
        document.getElementById('radarAvatars').innerHTML = '';
        document.getElementById('radarCount').textContent = '—';

        api('cohort_overview', {
            cohort_id: cohortId
        }).then(function (data) {
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
        const s = data.summary || {};

        document.getElementById('healthCount').textContent = (s.student_count || 0) + ' students';
        document.getElementById('healthStrip').innerHTML =
            '<div class="tcc-health-card"><div class="tcc-health-label">Students</div><div class="tcc-health-value">' + escapeHtml(s.student_count || 0) + '</div><div class="tcc-health-note">Active in this cohort</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">On Track</div><div class="tcc-health-value">' + escapeHtml(s.on_track_count || 0) + '</div><div class="tcc-health-note">No immediate action</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">At Risk</div><div class="tcc-health-value">' + escapeHtml(s.at_risk_count || 0) + '</div><div class="tcc-health-note">Needs monitoring</div></div>' +
            '<div class="tcc-health-card"><div class="tcc-health-label">Blocked</div><div class="tcc-health-value">' + escapeHtml(s.blocked_count || 0) + '</div><div class="tcc-health-note">Action required</div></div>';
    }

    function renderRadar(data) {
        const container = document.getElementById('radarAvatars');
        const count = document.getElementById('radarCount');
        const select = document.getElementById('studentSelect');
        const students = data.students || [];

        cohortStudents = students;
        container.innerHTML = '';
        select.innerHTML = '<option value="0">Select student…</option>';
        count.textContent = students.length + ' student' + (students.length === 1 ? '' : 's');

        if (!students.length) {
            container.innerHTML = '<div class="tcc-empty">No students found for this cohort.</div>';
            return;
        }

        students.forEach(function (s, idx) {
            const progress = clampPct(s.progress_pct);
            const color = radarColor(s);

            const opt = document.createElement('option');
            opt.value = s.student_id || 0;
            opt.textContent = (s.name || 'Student') + ' · ' + progress + '%';
            select.appendChild(opt);

            const verticalNudge = (idx % 3) * 7;
            const el = document.createElement('div');

            el.className = 'tcc-radar-avatar ' + color;
            el.style.left = progress + '%';
            el.style.top = (64 + verticalNudge) + 'px';
            el.title = (s.name || 'Student') + ' · ' + progress + '% · ' + (s.state || '');
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
            el.setAttribute('aria-label', (s.name || 'Student') + ' progress ' + progress + ' percent');
            el.setAttribute('data-student-id', s.student_id || 0);

            const path = photoPath(s);
            const inner = path !== ''
                ? '<span class="tcc-avatar-inner"><img src="' + escapeHtml(path) + '" alt="' + escapeHtml(s.name || 'Student') + '"></span>'
                : '<span class="tcc-avatar-inner">' + escapeHtml(s.avatar_initials || 'S') + '</span>';

            el.innerHTML = '<span class="tcc-radar-score">' + progress + '%</span>' + inner + '<span class="tcc-radar-label">' + escapeHtml(firstName(s.name)) + '</span>';
            el.onclick = function () {
                loadStudentPanel(s.student_id);
            };
            el.onkeydown = function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    el.click();
                }
            };

            container.appendChild(el);
        });
    }

    function selectStudentInUi(studentId) {
        selectedStudentId = parseInt(studentId, 10) || 0;

        const select = document.getElementById('studentSelect');
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
        const c = String(l.deadline_delta_class || '').toLowerCase();

        if (c === 'early' || c === 'on_time' || c === 'ok') return 'ok';
        if (c === 'late' || c === 'danger') return 'danger';
        if (c === 'warn') return 'warn';

        const label = String(l.deadline_delta_label || '').toLowerCase();
        if (label.indexOf('late') >= 0) return 'danger';
        if (label.indexOf('early') >= 0 || label.indexOf('on time') >= 0) return 'ok';

        return 'neutral';
    }

    function renderSummaryQuality(l) {
        const status = l.summary_status || 'not_started';
        const pct = summaryQualityPct(status, l.summary_score);
        const cls = summaryQualityClass(status, l.summary_score);

        return '<div class="tcc-quality-wrap"><div class="tcc-quality-top">' + pct + '%</div><div class="tcc-quality-bar"><span class="' + escapeHtml(cls) + '" style="width:' + pct + '%;"></span></div><div class="tcc-small-grey">' + escapeHtml(prettyStatus(status)) + '</div></div>';
    }

    function renderTestScore(l) {
        const score = (l.last_score !== null && l.last_score !== undefined) ? Number(l.last_score) : null;
        const cls = l.test_passed ? 'ok' : (score !== null ? 'danger' : 'neutral');
        const label = score !== null ? (score + '%') : '—';

        return '<span class="tcc-score-pill ' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function inlineSection(title, sub, bodyHtml, rightHtml) {
        return '<section class="tcc-inline-section"><div class="tcc-inline-section-head"><div><div class="tcc-inline-section-title">' + escapeHtml(title) + '</div><div class="tcc-inline-section-sub">' + escapeHtml(sub || '') + '</div></div>' + (rightHtml || '') + '</div><div class="tcc-inline-section-body">' + (bodyHtml || '') + '</div></section>';
    }

    function inlineMiniGrid(rows) {
        let html = '<div class="tcc-inline-mini-grid">';

        rows.forEach(function (row) {
            html += '<div class="tcc-inline-mini-card"><div class="tcc-inline-mini-label">' + escapeHtml(row[0]) + '</div><div class="tcc-inline-mini-value">' + escapeHtml(row[1]) + '</div></div>';
        });

        html += '</div>';
        return html;
    }

    function renderInlineSummary(resp, studentId, lessonId) {
        if (!resp || !resp.ok) {
            return inlineSection(
                '1. Lesson Summary Details',
                'Student summary and instructor review status.',
                '<div class="tcc-inline-error">Unable to load summary details.</div>',
                '<button type="button" class="tcc-detail-btn primary" onclick="openLessonSummary(' + parseInt(studentId, 10) + ',' + parseInt(lessonId, 10) + ')">Open Modal</button>'
            );
        }

        const d = resp.data || {};
        const s = d.summary || {};
        const lesson = d.lesson || {};
        const ai = d.ai_interpretation || {};
        const summaryHtml = rawSummaryHtml(s);

        const body = inlineMiniGrid([
            ['Module', lesson.course_title || '—'],
            ['Review Status', prettyStatus(s.review_status || '—')],
            ['Updated', niceDateTime(s.updated_at)],
            ['Local Time', niceTime(s.updated_at)]
        ]) +
            '<div style="display:grid;grid-template-columns:minmax(120px,.45fr) 1fr;gap:12px;margin-bottom:10px;"><div><div class="tcc-inline-mini-label">Review Status</div><div style="margin-top:6px;">' + reviewStatusPill(s.review_status || 'neutral') + '</div></div><div><div class="tcc-inline-mini-label">Review Score</div><div style="margin-top:6px;">' + reviewScoreBar(s.review_score) + '</div></div></div>' +
            '<div class="tcc-summary-safe-frame">' + summaryHtml + '</div>' +
            renderAiInterpretation(ai, studentId, lessonId);

        return inlineSection(
            '1. Lesson Summary Details',
            'Student summary, review status, score, and AI interpretation.',
            body,
            '<button type="button" class="tcc-detail-btn primary" onclick="openLessonSummary(' + parseInt(studentId, 10) + ',' + parseInt(lessonId, 10) + ')">Open Modal</button>'
        );
    }

    function renderInlineAttempts(resp, studentId, lessonId) {
        if (!resp || !resp.ok) {
            return inlineSection(
                '2. Progress Test Details',
                'Attempts, answers, scores, and audio trace.',
                '<div class="tcc-inline-error">Unable to load progress test details.</div>',
                '<button type="button" class="tcc-detail-btn primary" onclick="openAttemptDetails(' + parseInt(studentId, 10) + ',' + parseInt(lessonId, 10) + ')">Open Modal</button>'
            );
        }

        const d = resp.data || {};
        const attempts = d.attempts || [];
        let body = '';

        if (!attempts.length) {
            body = '<div class="tcc-inline-empty">No progress test attempts found for this lesson.</div>';
        } else {
            attempts.forEach(function (a) {
                const score = (a.score_pct !== null && a.score_pct !== undefined) ? (a.score_pct + '%') : '—';
                const cls = parseInt(a.pass_gate_met, 10) === 1 ? 'ok' : 'danger';

                body += '<div class="tcc-inline-attempt"><div class="tcc-inline-attempt-head"><div><div class="tcc-inline-attempt-title">Attempt ' + escapeHtml(a.attempt || '—') + ' · ' + escapeHtml(a.formal_result_code || a.status || '') + '</div><div class="tcc-inline-attempt-meta">Started: ' + escapeHtml(niceDateTime(a.started_at)) + ' · Completed: ' + escapeHtml(niceDateTime(a.completed_at)) + ' · Time: ' + escapeHtml(niceTime(a.completed_at)) + '</div></div><span class="tcc-score-pill ' + cls + '">' + escapeHtml(score) + '</span></div>';

                const items = a.items || [];

                if (!items.length) {
                    body += '<div class="tcc-inline-question"><div class="tcc-inline-question-answer">No answer-level detail found for this attempt.</div></div>';
                } else {
                    body += '<div class="tcc-chat-thread">';

                    items.forEach(function (item, idx) {
                        const q = item.prompt || item.question_text || 'Question';
                        const transcript = item.transcript_text || item.answer_text || item.student_answer || '—';
                        const answerScore = formatScoreValue(item.score_points, item.max_points);
                        const audio = item.audio_url || item.audio_path || item.answer_audio_url || item.recording_url || item.media_url || '';

                        body += '<div class="tcc-chat-bubble ai"><strong>Question ' + (idx + 1) + ':</strong> ' + escapeHtml(q) + '</div>';
                        body += '<div class="tcc-chat-bubble student" onclick="openAnswerAudioModal(' + jsArg(q) + ',' + jsArg(transcript) + ',' + jsArg(audio) + ',' + jsArg(answerScore) + ')">' + escapeHtml(transcript) + '<div class="tcc-chat-score">Score: ' + escapeHtml(answerScore) + '</div><span class="tcc-chat-play">▶ Play audio</span></div>';
                    });

                    body += '</div>';
                }

                body += '</div>';
            });
        }

        return inlineSection(
            '2. Progress Test Details',
            'Attempt history, answer detail, score trace, and clickable audio transcript bubbles.',
            body,
            '<button type="button" class="tcc-detail-btn primary" onclick="openAttemptDetails(' + parseInt(studentId, 10) + ',' + parseInt(lessonId, 10) + ')">Open Modal</button>'
        );
    }

    function renderInlineInterventions(resp, studentId, lessonId) {
        if (!resp || !resp.ok) {
            return inlineSection(
                '3. Interventions',
                'Required actions, extensions, emails, and progression events.',
                '<div class="tcc-inline-error">Unable to load intervention details.</div>',
                '<button type="button" class="tcc-detail-btn primary" onclick="openInterventions(' + parseInt(studentId, 10) + ',' + parseInt(lessonId, 10) + ')">Open Modal</button>'
            );
        }

        const d = resp.data || {};
        const groups = [
            ['Required Actions', d.required_actions || []],
            ['Deadline Overrides / Extensions', d.deadline_overrides || []],
            ['Email Trace', d.emails || []],
            ['Progression Events', d.events || []]
        ];

        let body = '';

        groups.forEach(function (group) {
            const title = group[0];
            const items = group[1] || [];

            body += '<div class="tcc-inline-section-title" style="font-size:12px;margin:10px 0 8px;">' + escapeHtml(title) + ' (' + items.length + ')</div>';

            if (!items.length) {
                body += '<div class="tcc-inline-empty">No records found.</div>';
            } else {
                items.slice(0, 8).forEach(function (item) {
                    const label = item.title || item.email_type || item.event_type || item.override_type || item.action_type || ('Record #' + (item.id || ''));
                    const meta = [
                        item.status || item.sent_status || '',
                        item.created_at ? niceDateTime(item.created_at) : '',
                        item.sent_at ? ('Sent ' + niceDateTime(item.sent_at)) : ''
                    ].filter(Boolean).join(' · ');

                    body += '<div class="tcc-inline-intervention tcc-intervention-clickable" onclick="openInterventionDetailModal(' + serializeForOnclick(item) + ')"><div class="tcc-inline-intervention-title">' + escapeHtml(label) + '</div><div class="tcc-inline-intervention-meta">' + escapeHtml(meta || '—') + '</div></div>';
                });

                if (items.length > 8) {
                    body += '<div class="tcc-inline-empty">+' + (items.length - 8) + ' more records. Open modal for the full trace.</div>';
                }
            }
        });

        return inlineSection(
            '3. Interventions',
            'Chronological intervention evidence connected to this lesson. Tap any item for full details.',
            body,
            '<button type="button" class="tcc-detail-btn primary" onclick="openInterventions(' + parseInt(studentId, 10) + ',' + parseInt(lessonId, 10) + ')">Open Modal</button>'
        );
    }

    function renderInlineLessonDetail(summaryResp, attemptsResp, interventionsResp, studentId, lessonId) {
        return '<div class="tcc-inline-detail-stack">' +
            renderInlineSummary(summaryResp, studentId, lessonId) +
            renderInlineAttempts(attemptsResp, studentId, lessonId) +
            renderInlineInterventions(interventionsResp, studentId, lessonId) +
        '</div>';
    }

    function toggleLessonInlineDetail(id, studentId, lessonId) {
        const el = document.getElementById(id);
        if (!el) return;

        if (el.classList.contains('open')) {
            el.classList.remove('open');
            return;
        }

        el.classList.add('open');

        if (el.getAttribute('data-loaded') === '1') return;

        el.innerHTML = '<div class="tcc-inline-loading">Loading lesson detail sections…</div>';

        Promise.all([
            api('lesson_summary_detail', { cohort_id: cohortId, student_id: studentId, lesson_id: lessonId }).catch(function () { return { ok: false, error: 'summary_load_failed' }; }),
            api('lesson_attempts_detail', { cohort_id: cohortId, student_id: studentId, lesson_id: lessonId }).catch(function () { return { ok: false, error: 'attempts_load_failed' }; }),
            api('lesson_interventions_detail', { cohort_id: cohortId, student_id: studentId, lesson_id: lessonId }).catch(function () { return { ok: false, error: 'interventions_load_failed' }; })
        ]).then(function (results) {
            el.setAttribute('data-loaded', '1');
            el.innerHTML = renderInlineLessonDetail(results[0], results[1], results[2], studentId, lessonId);
        }).catch(function () {
            el.innerHTML = '<div class="tcc-inline-error">Unable to load lesson detail sections.</div>';
        });
    }

    function renderLessonTimeline(lessons) {
        const target = document.getElementById('studentLessonTimelineBody');
        if (!target) return;

        if (!lessons || !lessons.length) {
            target.innerHTML = '<div class="tcc-empty">No lesson rows returned for this student.</div>';
            return;
        }

        const modules = [];
        const moduleMap = {};

        lessons.forEach(function (l) {
            const key = String(l.course_id || l.course_title || 'module');

            if (!moduleMap[key]) {
                moduleMap[key] = {
                    course_id: l.course_id || 0,
                    course_title: l.course_title || 'Module',
                    lessons: []
                };
                modules.push(moduleMap[key]);
            }

            moduleMap[key].lessons.push(l);
        });

        let html = '<div class="tcc-module-stack">';

        modules.forEach(function (mod, moduleIndex) {
            const total = mod.lessons.length;
            const passed = mod.lessons.filter(function (l) { return !!l.test_passed; }).length;
            const avgScores = mod.lessons.filter(function (l) { return l.last_score !== null && l.last_score !== undefined; }).map(function (l) { return Number(l.last_score); });
            const avg = avgScores.length ? Math.round(avgScores.reduce(function (a, b) { return a + b; }, 0) / avgScores.length) : null;

            html += '<details class="tcc-module-card" ' + (moduleIndex === 0 ? 'open' : '') + '>';
            html += '<summary><div class="tcc-module-head"><div class="tcc-module-badge">' + (moduleIndex + 1) + '</div><div><div class="tcc-module-label">Module</div><div class="tcc-module-title">' + escapeHtml(mod.course_title) + '</div></div><div><div class="tcc-module-mini">Progress</div><div class="tcc-module-value">' + passed + '/' + total + '</div></div><div><div class="tcc-module-mini">Avg Score</div><div class="tcc-module-value">' + (avg !== null ? avg + '%' : '—') + '</div></div></div></summary>';
            html += '<div class="tcc-module-body"><div class="tcc-instructor-lesson-wrap"><table class="tcc-instructor-lesson-table"><colgroup><col style="width:27%"><col style="width:18%"><col style="width:13%"><col style="width:15%"><col style="width:11%"><col style="width:8%"><col style="width:8%"></colgroup><thead><tr><th>Lesson</th><th>Deadlines</th><th>Finished</th><th>Summary Quality</th><th>Progress Test</th><th>Attempts</th><th>Interventions</th></tr></thead><tbody>';

            mod.lessons.forEach(function (l, lessonIndex) {
                const lessonId = parseInt(l.lesson_id || 0, 10);
                const studentId = parseInt(selectedStudentId || 0, 10);
                const detailId = 'lessonDetail_' + String(l.course_id || moduleIndex) + '_' + String(l.lesson_id || lessonIndex);
                const ext = Number(l.extension_count || 0);
                const extText = ext === 1 ? '1 Extension' : ext + ' Extensions';
                const extClass = ext > 0 ? 'tcc-extension-warn' : 'tcc-extension-ok';
                const deltaClass = lessonDeltaClass(l);
                const interventionCount = Number(l.intervention_count || 0);

                html += '<tr class="tcc-lesson-row" onclick="toggleLessonInlineDetail(' + jsArg(detailId) + ',' + studentId + ',' + lessonId + ')">';
                html += '<td><div class="tcc-lesson-main"><span class="tcc-lesson-num">' + (lessonIndex + 1) + '.</span><div class="tcc-lesson-name">' + escapeHtml(l.lesson_title || 'Lesson') + '</div></div></td>';
                html += '<td><div class="tcc-date-stack"><div class="tcc-date-line"><span>Orig</span>' + escapeHtml(niceDate(l.original_deadline_utc)) + '</div><div class="tcc-date-line"><span>Eff</span>' + escapeHtml(niceDate(l.effective_deadline_utc)) + '</div><div class="' + extClass + '">' + escapeHtml(ext > 0 ? extText : 'No Extensions') + '</div></div></td>';
                html += '<td><div class="tcc-date-stack"><div class="tcc-date-line"><span>Finished</span>' + escapeHtml(niceDate(l.completed_at)) + '</div><div class="tcc-small-grey">&nbsp;</div><div class="tcc-delta ' + deltaClass + '">' + escapeHtml(l.deadline_delta_label || '—') + '</div></div></td>';
                html += '<td>' + renderSummaryQuality(l) + '</td>';
                html += '<td>' + renderTestScore(l) + '</td>';
                html += '<td><span class="tcc-count-mini info">' + escapeHtml(l.attempt_count || 0) + '</span></td>';
                html += '<td><span class="tcc-count-mini ' + (interventionCount > 0 ? 'warn' : 'neutral') + '">' + escapeHtml(interventionCount) + '</span></td>';
                html += '</tr>';
                html += '<tr class="tcc-lesson-detail-row"><td colspan="7"><div id="' + detailId + '" class="tcc-lesson-detail" data-loaded="0"></div></td></tr>';
            });

            html += '</tbody></table></div></div></details>';
        });

        html += '</div>';
        target.innerHTML = html;
    }

    function loadStudentLessons(studentId) {
        const target = document.getElementById('studentLessonTimelineBody');
        if (target) target.innerHTML = '<div class="tcc-timeline-loading">Loading lesson timeline…</div>';

        api('student_lessons', {
            cohort_id: cohortId,
            student_id: studentId
        }).then(function (data) {
            if (!data.ok) {
                if (target) target.innerHTML = '<div class="tcc-timeline-error">' + escapeHtml(data.message || data.error || 'Unable to load lesson timeline.') + '</div>';
                return;
            }

            renderLessonTimeline(data.lessons || []);
        }).catch(function () {
            if (target) target.innerHTML = '<div class="tcc-timeline-error">Unable to load lesson timeline.</div>';
        });
    }

    function loadStudentPanel(studentId) {
        selectStudentInUi(studentId);

        const panel = document.getElementById('studentPanel');
        if (!selectedStudentId || !panel) return;

        document.getElementById('studentPanelCount').textContent = 'Loading';
        panel.className = '';
        panel.innerHTML = '<div class="tcc-loading">Loading student snapshot…</div>';

        api('student_snapshot', {
            cohort_id: cohortId,
            student_id: selectedStudentId
        }).then(function (data) {
            if (!data.ok) {
                document.getElementById('studentPanelCount').textContent = 'Error';
                panel.innerHTML = '<div class="tcc-error">' + escapeHtml(data.message || data.error || 'Unable to load student snapshot.') + '</div>';
                return;
            }

            renderStudentPanel(data);
        }).catch(function () {
            document.getElementById('studentPanelCount').textContent = 'Error';
            panel.innerHTML = '<div class="tcc-error">Unable to load student snapshot.</div>';
        });
    }

	
function issueCanOneClickRepair(issue) {
    issue = issue || {};

    return issue.repair_allowed === true &&
        String(issue.blocker_category || '') === 'stale_bug' &&
        String(issue.repair_code || '') === 'cleanup_old_active_attempt_after_pass' &&
        issue.evidence &&
        parseInt(issue.evidence.test_id || 0, 10) > 0;
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

function executeTccRepairFromIssue(issueJson, btn) {
    let issue = {};

    try {
        issue = JSON.parse(issueJson || '{}');
    } catch (e) {
        openTccModal('Fix Issue', '<div class="tcc-error">Invalid repair payload.</div>');
        return;
    }

    const payload = repairPayloadFromIssue(issue, issue.student_id, issue.lesson_id);

    if (!issueCanOneClickRepair(issue)) {
        openTccModal('Fix Issue', '<div class="tcc-error">This issue is not eligible for one-click repair.</div>');
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Fixing…';
    }

    repairApi(payload).then(function (resp) {
        if (!resp.ok) {
            openTccModal('Fix Issue Failed', '<div class="tcc-error">' + escapeHtml(resp.message || resp.error || 'Repair failed.') + '</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(resp, null, 2)) + '</pre>');
            return;
        }

        openTccModal('Fix Issue Complete', '<div class="tcc-empty">Stale blocker cleaned and audit log written.</div><pre class="tcc-debug-pre">' + escapeHtml(JSON.stringify(resp, null, 2)) + '</pre>');

        if (selectedStudentId > 0) {
            loadStudentPanel(selectedStudentId);
        }

        loadQueue();
        loadOverview();
    }).catch(function () {
        openTccModal('Fix Issue Failed', '<div class="tcc-error">Unable to execute repair.</div>');
    }).finally(function () {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Fix Issue';
        }
    });
}	
	
	
    function renderStudentPanel(data) {
        const panel = document.getElementById('studentPanel');
        const st = data.student || {};
        const p = data.progress || {};
        const c = data.comparison || {};
        const m = data.motivation || {};
        const issues = data.main_issues || [];
        const radarStudent = cohortStudents.find(function (s) { return parseInt(s.student_id, 10) === parseInt(st.student_id, 10); }) || {};
        const merged = Object.assign({}, radarStudent, st);
        const color = radarColor(radarStudent);
        const progressPct = clampPct(p.progress_pct);

        document.getElementById('studentPanelCount').textContent = (issues.length || 0) + ' issue' + (issues.length === 1 ? '' : 's');

        let issueHtml = '';

        if (!issues.length) {
            issueHtml = '<div class="tcc-empty">No current blockers or system issues returned for this student.</div>';
        } else {
            issues.slice(0, 10).forEach(function (issue) {
                const reviewHref = blockerReviewHref(issue);
                const lessonId = parseInt(issue.lesson_id, 10) || 0;
                const safeIssueType = String(issue.type || 'manual_check').replace(/[^a-zA-Z0-9_\-]/g, '');

                let actions = '<div class="tcc-issue-actions">';

if (reviewHref !== '') {
    actions += '<a class="tcc-btn primary" href="' + escapeHtml(reviewHref) + '">Review</a>';
} else {
    actions += '<button class="tcc-btn secondary" type="button" onclick="openDebugReport(' + parseInt(st.student_id, 10) + ',' + lessonId + ',' + jsArg(safeIssueType) + ')">Inspect</button>';
}

if (issueCanOneClickRepair(issue)) {
    actions += '<button class="tcc-btn fix" type="button" onclick="executeTccRepairFromIssue(' + jsArg(JSON.stringify(issue)) + ', this)">Fix Issue</button>';
}

actions += '</div>';

                issueHtml += '<div class="tcc-issue-row"><div class="tcc-issue-main"><div class="tcc-issue-title">' + escapeHtml(issue.title || issue.type || 'Issue') + '</div><div class="tcc-issue-meta">Lesson ' + escapeHtml(issue.lesson_id || '—') + ' · ' + escapeHtml(issue.lesson_title || '') + ' · ' + escapeHtml(issue.status || '') + '</div></div>' + actions + '</div>';
            });

            if (issues.length > 10) {
                issueHtml += '<div class="tcc-issue-row"><div class="tcc-issue-main"><div class="tcc-issue-title">+' + (issues.length - 10) + ' more issue(s)</div><div class="tcc-issue-meta">The lesson module view below keeps the full instructor workflow organized.</div></div><div class="tcc-issue-actions"><button class="tcc-btn secondary" type="button" onclick="loadStudentLessons(' + parseInt(st.student_id, 10) + ')">Open Lessons</button></div></div>';
            }
        }

        const avgScore = c.avg_score !== null && c.avg_score !== undefined ? Number(c.avg_score) : null;
        const cohortAvgScore = c.cohort_avg_score !== null && c.cohort_avg_score !== undefined ? Number(c.cohort_avg_score) : null;
        const missed = Number(c.deadlines_missed || 0);
        const cohortMissed = Number(c.cohort_avg_deadlines_missed || 0);
        const failed = Number(c.failed_attempts || 0);
        const cohortFailed = Number(c.cohort_avg_failed_attempts || 0);

        const tiles =
            metricTile('Progress', progressPct + '%', progressPct, '', '' + (p.passed_lessons || 0) + '/' + (p.total_lessons || 0) + ' lessons passed') +
            metricTile('Avg Score', avgScore !== null ? avgScore + '%' : '—', metricPct(avgScore, cohortAvgScore, true), barClass(avgScore, cohortAvgScore, true), 'Cohort Average: ' + (cohortAvgScore !== null ? cohortAvgScore + '%' : '—')) +
            metricTile('Deadlines Missed', missed, metricPct(missed, cohortMissed, false), barClass(missed, cohortMissed, false), 'Cohort Average: ' + cohortMissed) +
            metricTile('Failed Attempts', failed, metricPct(failed, cohortFailed, false), barClass(failed, cohortFailed, false), 'Cohort Average: ' + cohortFailed);

        panel.className = '';
        panel.innerHTML =
            '<div class="tcc-student-head"><div class="tcc-student-avatar ' + escapeHtml(color) + '">' +
                (photoPath(merged) !== '' ? '<img src="' + escapeHtml(photoPath(merged)) + '" alt="' + escapeHtml(st.name || 'Student') + '">' : escapeHtml(st.avatar_initials || radarStudent.avatar_initials || 'S')) +
            '</div><div style="min-width:0;"><div class="tcc-student-name">' + escapeHtml(st.name || 'Student') + '</div><div class="tcc-student-email">' + escapeHtml(st.email || '') + '</div></div></div>' +
            '<div class="tcc-student-state-row"><span class="tcc-status-pill ' + escapeHtml(m.level || '') + '">' + escapeHtml(m.label || 'Motivation signal') + '</span><span class="tcc-status-pill">Trend: ' + escapeHtml(m.trend || '—') + '</span><span class="tcc-status-pill">Issues: ' + escapeHtml(issues.length) + '</span></div>' +
            '<div class="tcc-snapshot-grid">' + tiles + '</div>' +
            '<h3 class="tcc-section-title" style="font-size:17px;margin:4px 0 10px;">Current Blockers / Issues</h3>' +
            '<div class="tcc-issues-list">' + issueHtml + '</div>' +
            '<div class="tcc-panel-actions"><button class="tcc-btn primary" type="button" onclick="loadStudentLessons(' + parseInt(st.student_id, 10) + ')">Lessons</button><button class="tcc-btn secondary" type="button" onclick="openInterventions(selectedStudentId,0)">Interventions</button><button class="tcc-btn warn" type="button" onclick="openSystemWatchForStudent(' + parseInt(st.student_id, 10) + ')">System Watch</button></div>' +
            '<div class="tcc-lesson-timeline"><div class="tcc-lesson-timeline-head"><div><div class="tcc-lesson-timeline-title">Lessons by Module</div><div class="tcc-lesson-timeline-sub">Instructor overview styled like the student course page, grouped by module with compact deadline, summary, test, attempts, and intervention data.</div></div><span class="tcc-count-pill">Live</span></div><div id="studentLessonTimelineBody" class="tcc-timeline-loading">Loading lesson modules…</div></div>';

        loadStudentLessons(st.student_id);
    }

    function loadQueue() {
        document.getElementById('actionQueue').innerHTML = '<div class="tcc-loading">Loading action queue…</div>';
        document.getElementById('queueCount').textContent = '—';

        api('action_queue', {
            cohort_id: cohortId
        }).then(function (data) {
            const container = document.getElementById('actionQueue');
            const count = document.getElementById('queueCount');

            if (!data.ok) {
                showError('actionQueue', data.message || data.error || 'Unable to load action queue.');
                return;
            }

            const items = data.items || [];
            count.textContent = items.length + ' item' + (items.length === 1 ? '' : 's');
            container.innerHTML = '';

            if (!items.length) {
                container.innerHTML = '<div class="tcc-empty">No instructor actions required for this cohort right now.</div>';
                return;
            }

            items.forEach(function (item) {
                let reviewHref = '';

                if (item.token) {
                    if (item.action_type === 'instructor_approval') reviewHref = '/instructor/instructor_approval.php?token=' + encodeURIComponent(item.token);
                    else reviewHref = '/student/remediation_action.php?token=' + encodeURIComponent(item.token);
                }

                const severity = item.severity || 'low';
                const radarStudent = cohortStudents.find(function (s) { return parseInt(s.student_id, 10) === parseInt(item.student_id, 10); }) || {};
                const color = radarColor(radarStudent.state ? radarStudent : { state: item.severity === 'high' ? 'blocked' : 'at_risk', pending_action_count: 1 });
                const avatarObj = Object.assign({}, radarStudent, {
                    photo_path: item.photo_path || radarStudent.photo_path,
                    avatar_initials: item.avatar_initials || radarStudent.avatar_initials,
                    name: item.student_name
                });
                const lessonId = parseInt(item.lesson_id || 0, 10);
                const issueType = String(item.action_type || 'manual_check').replace(/[^a-zA-Z0-9_\-]/g, '');
                const el = document.createElement('div');

                el.className = 'tcc-item';
                el.innerHTML = '<div class="tcc-item-left">' + avatarHtml(avatarObj, 'tcc-avatar ' + color) + '<div class="tcc-meta"><div class="tcc-name">' + escapeHtml(item.student_name || 'Student') + '</div><div class="tcc-sub">' + escapeHtml(item.lesson_title || 'No lesson title') + '</div><div class="tcc-sub">' + escapeHtml(item.reason || item.action_type || 'Required action') + '</div><span class="tcc-severity ' + escapeHtml(severity) + '">' + escapeHtml(severity) + '</span></div></div><div class="tcc-actions">' +
                    (reviewHref ? '<a class="tcc-btn primary" href="' + escapeHtml(reviewHref) + '">Review</a>' : '<button class="tcc-btn primary" type="button" onclick="loadStudentPanel(' + parseInt(item.student_id || 0, 10) + ')">Review</button>') +
                    '<button class="tcc-btn secondary" type="button" onclick="openDebugReport(' + parseInt(item.student_id || 0, 10) + ',' + lessonId + ',' + jsArg(issueType) + ')">Inspect</button>' + '</div>';

                const left = el.querySelector('.tcc-item-left');
                if (left) {
                    left.style.cursor = 'pointer';
                    left.onclick = function () {
                        loadStudentPanel(item.student_id);
                    };
                }

                container.appendChild(el);
            });
        }).catch(function () {
            showError('actionQueue', 'Unable to load action queue.');
        });
    }

    window.openAnswerAudioModal = openAnswerAudioModal;

    window.openInterventionDetailModal = openInterventionDetailModal;

    window.openSummaryLargeModal = openSummaryLargeModal;

    window.openSummaryLargeModalById = openSummaryLargeModalById;

    window.closeTccModal = closeTccModal;

    window.openDebugReport = openDebugReport;

    window.openSystemWatchForStudent = openSystemWatchForStudent;

    window.openLessonSummary = openLessonSummary;

    window.generateAiSummaryAnalysis = generateAiSummaryAnalysis;

    window.openAttemptDetails = openAttemptDetails;

    window.openInterventions = openInterventions;

    window.loadStudentLessons = loadStudentLessons;

    window.toggleLessonInlineDetail = toggleLessonInlineDetail;

    window.toggleLessonDetail = toggleLessonInlineDetail;

    window.generateAiSummaryAnalysisFromButton = generateAiSummaryAnalysisFromButton;

    window.executeTccRepairFromIssue = executeTccRepairFromIssue;

    loadCohorts();
	
	
    document.getElementById('cohortSelect').addEventListener('change', function () {
        cohortId = parseInt(this.value, 10) || 0;

        const match = Array.prototype.find.call(this.options, function (o) {
            return parseInt(o.value, 10) === cohortId;
        });

        window.tccCohortTimezone = (match && match.getAttribute('data-timezone')) || window.tccCohortTimezone || 'UTC';
        loadAll();
    });

    document.getElementById('studentSelect').addEventListener('change', function () {
        const sid = parseInt(this.value, 10) || 0;
        if (sid > 0) loadStudentPanel(sid);
    });

    loadCohorts();
	
	window.executeTccRepairFromIssue = executeTccRepairFromIssue;
	
})();
</script>


<?php cw_footer(); ?>
