<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCalendarRepository.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCalendarService.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function cmpcal_flash(string $type, string $message): void
{
    $_SESSION['_ipca_compliance_calendar_flash'] = array('type' => $type, 'message' => $message);
}

function cmpcal_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_calendar_flash']) || !is_array($_SESSION['_ipca_compliance_calendar_flash'])) {
        return null;
    }
    $flash = $_SESSION['_ipca_compliance_calendar_flash'];
    unset($_SESSION['_ipca_compliance_calendar_flash']);
    return $flash;
}

function cmpcal_return_url(): string
{
    $date = substr(trim((string)($_POST['return_date'] ?? '')), 0, 10);
    $view = trim((string)($_POST['return_view'] ?? ''));
    $scroll = (int)($_POST['return_scroll'] ?? 0);
    $args = array();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $args['date'] = $date;
    }
    if (in_array($view, array('month', 'week', 'day'), true)) {
        $args['view'] = $view;
    }
    if ($scroll > 0) {
        $args['scroll'] = $scroll;
    }
    return '/admin/compliance/calendar.php' . ($args ? '?' . http_build_query($args) : '');
}

function cmpcal_picker_label(string $code, string $title, string $status): string
{
    $parts = array();
    if (trim($code) !== '') {
        $parts[] = trim($code);
    }
    if (trim($title) !== '') {
        $parts[] = trim($title);
    }
    $label = implode(' — ', $parts);
    if (trim($status) !== '') {
        $label .= ' (' . trim($status) . ')';
    }
    return $label !== '' ? $label : 'Record';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_manual_event') {
            $id = ComplianceCalendarService::createManualEvent($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'event_type' => (string)($_POST['event_type'] ?? 'other'),
                'date' => (string)($_POST['date'] ?? ''),
                'start_time' => (string)($_POST['start_time'] ?? ''),
                'end_time' => (string)($_POST['end_time'] ?? ''),
                'timezone' => (string)($_POST['timezone'] ?? 'UTC'),
                'description' => (string)($_POST['description'] ?? ''),
                'linked_object_type' => (string)($_POST['linked_object_type'] ?? ''),
                'linked_object_id' => (int)($_POST['linked_object_id'] ?? 0),
                'is_all_day' => ((string)($_POST['all_day'] ?? '0') === '1'),
            ), $uid);
            cmpcal_flash('success', 'Manual calendar event created (CAL-' . $id . ').');
            redirect(cmpcal_return_url());
        }

        if ($action === 'calendar_change') {
            $result = ComplianceCalendarService::requestOrApplyChange($pdo, array(
                'event_id' => (string)($_POST['event_id'] ?? ''),
                'proposed_starts_at' => (string)($_POST['proposed_starts_at'] ?? ''),
                'proposed_ends_at' => (string)($_POST['proposed_ends_at'] ?? ''),
                'reason' => (string)($_POST['reason'] ?? ''),
            ), $uid);
            if ($result['mode'] === 'meeting_updated') {
                cmpcal_flash('success', 'Meeting schedule updated.');
            } elseif ($result['mode'] === 'manual_updated') {
                cmpcal_flash('success', 'Manual calendar event updated.');
            } else {
                cmpcal_flash('warn', 'Calendar change request submitted for approval.');
            }
            redirect(cmpcal_return_url());
        }

        if ($action === 'update_manual_event') {
            $id = (int)($_POST['calendar_event_id'] ?? 0);
            ComplianceCalendarService::updateManualEvent($pdo, $id, array(
                'title' => (string)($_POST['title'] ?? ''),
                'event_type' => (string)($_POST['event_type'] ?? 'other'),
                'date' => (string)($_POST['date'] ?? ''),
                'start_time' => (string)($_POST['start_time'] ?? ''),
                'end_time' => (string)($_POST['end_time'] ?? ''),
                'timezone' => (string)($_POST['timezone'] ?? 'UTC'),
                'description' => (string)($_POST['description'] ?? ''),
                'linked_object_type' => (string)($_POST['linked_object_type'] ?? ''),
                'linked_object_id' => (int)($_POST['linked_object_id'] ?? 0),
                'is_all_day' => ((string)($_POST['all_day'] ?? '0') === '1'),
            ), $uid);
            cmpcal_flash('success', 'Manual calendar event updated.');
            redirect(cmpcal_return_url());
        }

        if ($action === 'link_manual_event') {
            ComplianceCalendarService::linkManualEvent(
                $pdo,
                (int)($_POST['calendar_event_id'] ?? 0),
                (string)($_POST['linked_object_type'] ?? ''),
                (int)($_POST['linked_object_id'] ?? 0),
                $uid
            );
            cmpcal_flash('success', 'Manual calendar event link updated.');
            redirect(cmpcal_return_url());
        }

        if ($action === 'delete_manual_event') {
            $id = (int)($_POST['calendar_event_id'] ?? 0);
            ComplianceCalendarService::deleteManualEvent($pdo, $id, $uid);
            cmpcal_flash('success', 'Manual calendar event deleted.');
            redirect(cmpcal_return_url());
        }

        if ($action === 'review_change_request') {
            $applyError = ComplianceCalendarService::reviewChangeRequest(
                $pdo,
                (int)($_POST['request_id'] ?? 0),
                (string)($_POST['decision'] ?? ''),
                (string)($_POST['reviewer_notes'] ?? ''),
                $uid
            );
            if ($applyError !== null && $applyError !== '') {
                cmpcal_flash('warn', 'Calendar change request reviewed. ' . $applyError);
            } else {
                cmpcal_flash('success', 'Calendar change request reviewed.');
            }
            redirect(cmpcal_return_url());
        }
    } catch (Throwable $e) {
        cmpcal_flash('error', $e->getMessage());
        redirect(cmpcal_return_url());
    }
}

$events = ComplianceCalendarRepository::listEvents($pdo);
$stats = ComplianceCalendarRepository::stats($events);
$sources = ComplianceCalendarRepository::connectedSources($pdo);
$tableStatus = ComplianceCalendarService::tableStatus($pdo);
$pendingRequests = ComplianceCalendarService::listChangeRequests($pdo, 'pending', 20);
$linkableGroups = ComplianceCommsCenterEngine::listLinkablePickerOptions($pdo, 300);
try {
    $st = $pdo->query(
        "SELECT id, rule_code, title, monitor_kind, is_active
           FROM ipca_compliance_monitor_rules
          ORDER BY updated_at DESC, id DESC
          LIMIT 300"
    );
    $opts = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        $status = ((int)($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . ' / ' . (string)($row['monitor_kind'] ?? '');
        $opts[] = array(
            'id' => (string)(int)$row['id'],
            'label' => cmpcal_picker_label((string)($row['rule_code'] ?? ''), (string)($row['title'] ?? ''), $status),
        );
    }
    if ($opts !== array()) {
        $linkableGroups[] = array('type' => 'regulatory_review', 'type_label' => 'Regulatory Reviews', 'options' => $opts);
    }
} catch (Throwable) {
    // Optional picker source; ignore missing monitoring tables.
}
$flash = cmpcal_flash_take();
$futureCounts = array();
$todayYmd = date('Y-m-d');
foreach ($events as $event) {
    $key = (string)($event['event_type'] ?? 'other');
    $start = substr((string)($event['starts_at'] ?? ''), 0, 10);
    if ($start >= $todayYmd) {
        $futureCounts[$key] = (int)($futureCounts[$key] ?? 0) + 1;
    }
}

$eventTypes = array(
    array('key' => 'internal_audit', 'label' => 'Internal Audit', 'icon' => 'clipboard-check'),
    array('key' => 'authority_audit', 'label' => 'Authority Audit', 'icon' => 'shield'),
    array('key' => 'audit_window', 'label' => 'Audit Window', 'icon' => 'calendar'),
    array('key' => 'rca_cap_deadline', 'label' => 'RCA/CAP Deadline', 'icon' => 'clock'),
    array('key' => 'corrective_action_deadline', 'label' => 'Corrective Action Deadline', 'icon' => 'wrench'),
    array('key' => 'effectiveness_review', 'label' => 'Effectiveness Review', 'icon' => 'clipboard-check'),
    array('key' => 'meeting', 'label' => 'Compliance Meeting', 'icon' => 'users'),
    array('key' => 'regulatory_review', 'label' => 'Regulatory Review', 'icon' => 'scale'),
    array('key' => 'manual_change', 'label' => 'Manual Change', 'icon' => 'book'),
    array('key' => 'cyber_part_is', 'label' => 'Cyber / Part-IS', 'icon' => 'shield'),
    array('key' => 'other', 'label' => 'Other Event', 'icon' => 'circle'),
);

cw_header('Compliance · Schedule');

compliance_page_open(array(
    'overline' => 'Compliance Operating System',
    'title' => 'Compliance Schedule',
    'description' => 'Scheduled audits, meetings, deadlines and governance events.',
    'actions' => array(
        array('label' => '+ New Event', 'modal' => 'calendarNewEventModal', 'icon' => 'plus'),
        array('label' => 'Schedule Settings', 'modal' => 'calendarSettingsModal', 'icon' => 'settings'),
    ),
    'stats' => array(
        array('label' => 'Total Events This Month', 'value' => (int)$stats['month_events'], 'sub' => 'read-only projection'),
        array('label' => 'Upcoming Audits', 'value' => (int)$stats['upcoming_audits'], 'sub' => 'internal + authority'),
        array('label' => 'Open Deadlines', 'value' => (int)$stats['open_deadlines'], 'sub' => 'governed dates', 'tone' => ((int)$stats['open_deadlines'] > 0 ? 'warn' : 'ok')),
        array('label' => 'Meetings Planned', 'value' => (int)$stats['meetings_planned'], 'sub' => 'scheduled / live'),
        array('label' => 'Overdue Items', 'value' => (int)$stats['overdue_items'], 'sub' => 'requires attention', 'tone' => ((int)$stats['overdue_items'] > 0 ? 'crit' : 'ok')),
    ),
    'flash' => $flash,
));
?>
<style>
  .cmpcal-shell{display:grid;grid-template-columns:310px minmax(0,1fr);gap:18px;align-items:start;}
  .cmpcal-side{display:flex;flex-direction:column;gap:18px;}
  .cmpcal-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 4px 18px rgba(15,23,42,.05);}
  .cmpcal-card-pad{padding:18px;}
  .cmpcal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}
  .cmpcal-title{margin:0;font-size:15px;color:#0f172a;font-weight:800;}
  .cmpcal-muted{color:#64748b;font-size:12.5px;line-height:1.45;}
  .cmpcal-mini-nav{display:flex;gap:6px;}
  .cmpcal-icon-btn,.cmpcal-toolbar button{display:inline-flex;align-items:center;justify-content:center;min-height:20px;border:1px solid rgba(23,52,93,.14);background:#f8fafc;color:#17345d;border-radius:999px;padding:0 8px;font-size:9.5px;font-weight:820;cursor:pointer;box-shadow:none;transition:background .16s ease,border-color .16s ease,transform .16s ease;}
  .cmpcal-icon-btn{width:20px;padding:0;}
  .cmpcal-icon-btn:hover,.cmpcal-toolbar button:hover{background:#eef4ff;border-color:#b7c9e4;transform:translateY(-1px);}
  .cmpcal-weekdays,.cmpcal-mini-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px;}
  .cmpcal-weekdays{margin-bottom:6px;color:#64748b;font-size:11px;font-weight:800;text-align:center;}
  .cmpcal-mini-day{position:relative;display:flex;align-items:center;justify-content:center;min-height:30px;border:0;border-radius:999px;background:transparent;color:#0f172a;font-size:12px;font-weight:760;cursor:pointer;transition:background .15s ease,color .15s ease;}
  .cmpcal-mini-day:hover{background:#eef4ff;color:#17345d;}
  .cmpcal-mini-day.is-out{color:#a8b3c3;background:transparent;}
  .cmpcal-mini-day span{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;border-radius:999px;}
  .cmpcal-mini-day.is-today span{border:2px solid #2f5f9f;}
  .cmpcal-mini-day.is-selected span{background:#17345d;color:#fff;}
  .cmpcal-mini-day.is-selected.is-today span{border-color:#17345d;}
  .cmpcal-dot{position:absolute;left:50%;bottom:4px;width:5px;height:5px;border-radius:999px;background:#3a6fd0;transform:translateX(-50%);}
  .cmpcal-filter-list{display:flex;flex-direction:column;gap:8px;}
  .cmpcal-filter{display:grid;grid-template-columns:18px 18px minmax(0,1fr) auto;gap:8px;align-items:center;}
  .cmpcal-filter input{width:15px;height:15px;accent-color:#17345d;}
  .cmpcal-svg{width:17px;height:17px;display:inline-flex;color:#284e85;}
  .cmpcal-type-pill{display:inline-flex;align-items:center;min-width:0;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;color:#17345d;background:#eef4ff;border:1px solid #d7e5fb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .cmpcal-count{font-size:11px;font-weight:850;color:#475569;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:3px 7px;}
  .cmpcal-legend{border-top:1px dashed #cbd5e1;margin-top:16px;padding-top:14px;display:grid;gap:8px;}
  .cmpcal-legend-row{display:grid;grid-template-columns:36px minmax(0,1fr);align-items:center;column-gap:12px;color:#334155;font-size:12px;font-weight:680;}
  .cmpcal-legend-swatch{width:28px;height:12px;border-radius:999px;background:#dbeafe;border:1px solid #7aa7e8;}
  .cmpcal-legend-swatch.is-dashed{border-style:dashed;opacity:.68;}
  .cmpcal-legend-swatch.is-red{background:#fee2e2;border-color:#ef4444;}
  .cmpcal-legend-swatch.is-yellow{background:#fef3c7;border-color:#f59e0b;}
  .cmpcal-source-empty{margin-top:12px;padding:10px 12px;border-radius:12px;background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b;font-size:12px;line-height:1.45;}
  .cmpcal-source-empty.is-warn{background:#fffbeb;border-color:#f59e0b;color:#92400e;}
  .cmpcal-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #e2e8f0;}
  .cmpcal-toolbar-left,.cmpcal-toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .cmpcal-range{font-size:15px;font-weight:850;color:#0f172a;min-width:220px;}
  .cmpcal-select{border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#0f172a;padding:8px 10px;font-size:13px;font-weight:720;}
  .cmpcal-main{padding:14px;}
  .cmpcal-view{display:none;}
  .cmpcal-view.is-active{display:block;}
  .cmpcal-month-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-top:1px solid #e2e8f0;border-left:1px solid #e2e8f0;border-radius:14px;overflow:hidden;}
  .cmpcal-month-head{background:#f8fafc;color:#475569;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.06em;padding:10px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;}
  .cmpcal-month-day{min-height:132px;background:#fff;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:8px;cursor:pointer;transition:background .15s ease;}
  .cmpcal-month-day:hover{background:#fbfdff;}
  .cmpcal-month-day.is-out{background:#f8fafc;color:#94a3b8;}
  .cmpcal-month-day.is-selected{box-shadow:inset 0 0 0 2px rgba(31,64,121,.25);}
  .cmpcal-month-day.is-dense{background:#f5f8fc;}
  .cmpcal-month-day.is-drop-target,.cmpcal-all-day-cell.is-drop-target,.cmpcal-time-col.is-drop-target{background:#eef4ff !important;box-shadow:inset 0 0 0 2px rgba(31,64,121,.20);}
  .cmpcal-day-number{display:inline-flex;align-items:center;justify-content:center;width:27px;height:27px;border-radius:999px;font-size:12px;font-weight:850;color:#0f172a;}
  .cmpcal-day-number.is-today{border:2px solid #2f5f9f;color:#17345d;}
  .cmpcal-day-number.is-selected{background:#17345d;color:#fff;}
  .cmpcal-events{display:flex;flex-direction:column;gap:4px;margin-top:7px;}
  .cmpcal-event{--event-bg:#eaf2ff;--event-border:#9fbdec;--event-text:#17345d;display:flex;align-items:center;gap:5px;min-width:0;border-radius:999px;border:1px solid var(--event-border);background:var(--event-bg);color:var(--event-text);padding:2px 7px;font-size:11px;font-weight:820;line-height:1.2;cursor:grab;box-shadow:none;min-height:24px;}
  .cmpcal-event:active{cursor:grabbing;}
  .cmpcal-event svg{width:12px;height:12px;flex:0 0 12px;}
  .cmpcal-event span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .cmpcal-event.is-pending{border-style:dashed;opacity:.68;}
  .cmpcal-event.is-dragging{opacity:.55;}
  .cmpcal-event.is-overdue{--event-bg:#fee2e2;--event-border:#ef4444;--event-text:#991b1b;}
  .cmpcal-event.is-awaiting{--event-bg:#fef3c7;--event-border:#f59e0b;--event-text:#92400e;}
  .cmpcal-event.is-locked::after{content:"";width:11px;height:11px;margin-left:auto;background:currentColor;mask:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" d="M17 9V7A5 5 0 0 0 7 7v2H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1ZM9 9V7a3 3 0 0 1 6 0v2H9Z"/></svg>') center/contain no-repeat;}
  .cmpcal-more{font-size:11px;color:#475569;font-weight:850;margin-top:2px;}
  .cmpcal-type-internal_audit{--event-bg:#eaf2ff;--event-border:#8fb2e8;--event-text:#17345d;}
  .cmpcal-type-authority_audit{--event-bg:#eef2ff;--event-border:#818cf8;--event-text:#3730a3;}
  .cmpcal-type-audit_window{--event-bg:#f1f5f9;--event-border:#94a3b8;--event-text:#334155;}
  .cmpcal-type-rca_cap_deadline{--event-bg:#fff7ed;--event-border:#fb923c;--event-text:#9a3412;}
  .cmpcal-type-corrective_action_deadline{--event-bg:#ecfdf5;--event-border:#34d399;--event-text:#065f46;}
  .cmpcal-type-effectiveness_review{--event-bg:#f0f9ff;--event-border:#38bdf8;--event-text:#075985;}
  .cmpcal-type-meeting{--event-bg:#f5f3ff;--event-border:#a78bfa;--event-text:#5b21b6;}
  .cmpcal-type-regulatory_review{--event-bg:#fdf4ff;--event-border:#d946ef;--event-text:#86198f;}
  .cmpcal-type-manual_change{--event-bg:#f7fee7;--event-border:#a3e635;--event-text:#3f6212;}
  .cmpcal-type-cyber_part_is{--event-bg:#ecfeff;--event-border:#22d3ee;--event-text:#155e75;}
  .cmpcal-type-other{--event-bg:#f8fafc;--event-border:#cbd5e1;--event-text:#334155;}
  .cmpcal-week-shell{border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;background:#fff;}
  .cmpcal-week-head{display:grid;grid-template-columns:70px repeat(7,minmax(0,1fr));border-bottom:1px solid #e2e8f0;background:#f8fafc;}
  .cmpcal-week-head.day{grid-template-columns:70px minmax(0,1fr);}
  .cmpcal-week-label{padding:10px;border-left:1px solid #e2e8f0;text-align:center;color:#334155;font-size:12px;font-weight:850;}
  .cmpcal-week-label.is-today{background:#edf5ff;}
  .cmpcal-week-label .num{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;margin-left:4px;}
  .cmpcal-week-label.is-today .num{border:2px solid #2f5f9f;color:#17345d;}
  .cmpcal-all-day{display:grid;grid-template-columns:70px repeat(7,minmax(0,1fr));border-bottom:1px solid #e2e8f0;}
  .cmpcal-all-day.day{grid-template-columns:70px minmax(0,1fr);}
  .cmpcal-all-day-label{display:flex;align-items:center;justify-content:center;text-align:center;padding:9px;color:#64748b;font-size:11px;font-weight:850;background:#fbfdff;}
  .cmpcal-all-day-cell{min-height:42px;padding:5px;border-left:1px solid #e2e8f0;}
  .cmpcal-timeline{display:grid;grid-template-columns:70px repeat(7,minmax(0,1fr));position:relative;height:960px;background:#fff;}
  .cmpcal-timeline.day{grid-template-columns:70px minmax(0,1fr);}
  .cmpcal-hours{display:grid;grid-template-rows:repeat(24,40px);border-right:1px solid #e2e8f0;background:#fbfdff;}
  .cmpcal-hour{position:relative;display:flex;align-items:flex-start;justify-content:center;color:#64748b;font-size:11px;font-weight:740;text-align:center;padding:0;}
  .cmpcal-hour::after{content:"";position:absolute;left:70px;right:-4000px;top:0;border-top:1px solid #eef2f7;}
  .cmpcal-time-col{position:relative;border-left:1px solid #e2e8f0;background:linear-gradient(to bottom,#fff 0,#fff 39px,#f8fafc 40px);background-size:100% 40px;}
  .cmpcal-time-col.is-today{background-color:#f8fbff;}
  .cmpcal-time-event{position:absolute;left:6px;right:6px;border-radius:14px;border:1px solid var(--event-border);background:var(--event-bg);color:var(--event-text);padding:5px 18px 5px 8px;font-size:11.5px;font-weight:820;overflow:hidden;box-shadow:none;cursor:grab;z-index:2;}
  .cmpcal-time-event:active{cursor:grabbing;}
  .cmpcal-time-event svg{width:12px;height:12px;vertical-align:-2px;margin-right:4px;flex:0 0 12px;color:var(--event-text);}
  .cmpcal-time-event-title{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--event-text);font-weight:850;line-height:1.15;}
  .cmpcal-time-event .time{display:block;font-size:10.5px;opacity:.78;margin-top:2px;}
  .cmpcal-time-event.is-compact .time{display:none;}
  .cmpcal-time-event.is-moving{position:fixed !important;z-index:80;pointer-events:none;box-shadow:0 18px 36px rgba(15,23,42,.22);opacity:.94;cursor:grabbing;}
  .cmpcal-resize-handle{position:absolute;left:10px;right:10px;height:8px;z-index:4;cursor:ns-resize;}
  .cmpcal-resize-handle.top{top:0;}
  .cmpcal-resize-handle.bottom{bottom:0;}
  .cmpcal-resize-handle::after{content:"";position:absolute;left:50%;top:3px;width:28px;height:2px;border-radius:999px;background:currentColor;opacity:.28;transform:translateX(-50%);}
  .cmpcal-time-event.is-resizing{opacity:.72;box-shadow:0 0 0 2px rgba(31,64,121,.18);}
  .cmpcal-now-line{position:absolute;height:2px;background:#dc2626;left:0;right:0;z-index:3;}
  .cmpcal-now-line::before{content:"";position:absolute;left:-5px;top:-4px;width:10px;height:10px;background:#dc2626;border-radius:999px;}
  .cmpcal-empty-panel{padding:18px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#64748b;font-size:13px;}
  .cmpcal-tooltip{position:fixed;z-index:50;max-width:280px;background:#0f172a;color:#fff;border-radius:12px;padding:10px 12px;box-shadow:0 16px 40px rgba(15,23,42,.25);font-size:12px;line-height:1.4;display:none;pointer-events:none;}
  .cmpcal-tooltip strong{display:block;font-size:13px;margin-bottom:5px;}
  .cmpcal-drag-ghost{position:fixed;z-index:70;pointer-events:none;border-radius:14px;border:1px solid var(--event-border);background:var(--event-bg);color:var(--event-text);padding:7px 10px;min-width:190px;max-width:280px;box-shadow:0 18px 36px rgba(15,23,42,.24);font-size:12px;font-weight:850;opacity:.96;}
  .cmpcal-drag-ghost .time{display:block;font-size:11px;opacity:.8;margin-top:3px;}
  .cmpcal-field.is-disabled{opacity:.48;}
  .cmpcal-field.is-disabled input{background:#f8fafc;cursor:not-allowed;}
  .cmpcal-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}
  .cmpcal-field span{display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:4px;}
  .cmpcal-field input,.cmpcal-field select,.cmpcal-field textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;font:inherit;font-size:13px;}
  .cmpcal-field textarea{min-height:82px;resize:vertical;}
  .cmpcal-modal-note{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:12px;padding:10px 12px;color:#475569;font-size:13px;margin:10px 0;}
  .cmpcal-warning{border-color:#f59e0b;background:#fffbeb;color:#92400e;}
  .cmpcal-event-detail{display:grid;grid-template-columns:170px minmax(0,1fr);gap:8px 14px;font-size:13px;}
  .cmpcal-event-detail dt{color:#64748b;font-weight:800;}
  .cmpcal-event-detail dd{margin:0;color:#0f172a;font-weight:650;overflow-wrap:anywhere;}
  .cmpcal-footer-link{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:12px;padding:0 16px;text-decoration:none;font-weight:800;border:1px solid #cbd5e1;background:#e5e7eb;color:#64748b;cursor:not-allowed;pointer-events:none;}
  .cmpcal-footer-link.is-active{background:#12355f;border-color:#12355f;color:#fff;cursor:pointer;pointer-events:auto;}
  .cmpcal-footer-link.is-active:hover{background:#1f4079;border-color:#1f4079;color:#fff;}
  #cmpcalDeleteEvent:hover:not(:disabled){background:#dc2626;border-color:#dc2626;color:#fff;}
  .cmpcal-settings-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px;margin-top:10px;}
  .cmpcal-settings-list label{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:720;}
  .cmpcal-queue{margin-top:18px;}
  .cmpcal-queue-list{display:grid;gap:10px;}
  .cmpcal-queue-item{border:1px solid #e2e8f0;border-radius:14px;padding:12px;background:#fff;}
  .cmpcal-queue-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:8px;}
  .cmpcal-queue-title{font-weight:850;color:#0f172a;font-size:13px;}
  .cmpcal-queue-meta{color:#64748b;font-size:12px;line-height:1.45;}
  .cmpcal-queue-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;}
  .cmpcal-queue-actions textarea{min-width:260px;min-height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;font:inherit;font-size:12px;}
  @media (max-width:1100px){.cmpcal-shell{grid-template-columns:1fr}.cmpcal-side{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));}.cmpcal-toolbar{align-items:flex-start;flex-direction:column}.cmpcal-range{min-width:0}.cmpcal-week-head,.cmpcal-all-day,.cmpcal-timeline{min-width:900px}.cmpcal-main{overflow:auto;}}
</style>

<div class="cmpcal-shell" id="cmpcalApp">
  <aside class="cmpcal-side">
    <section class="cmpcal-card cmpcal-card-pad">
      <div class="cmpcal-head">
        <h2 class="cmpcal-title" id="cmpcalMiniTitle">Month Year</h2>
        <div class="cmpcal-mini-nav">
          <button type="button" class="cmpcal-icon-btn" id="cmpcalMiniPrev" aria-label="Previous month">&lt;</button>
          <button type="button" class="cmpcal-icon-btn" id="cmpcalMiniNext" aria-label="Next month">&gt;</button>
        </div>
      </div>
      <div class="cmpcal-weekdays"><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span></div>
      <div class="cmpcal-mini-grid" id="cmpcalMiniGrid"></div>
    </section>

    <section class="cmpcal-card cmpcal-card-pad">
      <div class="cmpcal-head">
        <h2 class="cmpcal-title">Event Type Filters</h2>
      </div>
      <div class="cmpcal-filter-list">
        <?php foreach ($eventTypes as $type): ?>
          <?php $count = (int)($futureCounts[$type['key']] ?? 0); ?>
          <label class="cmpcal-filter">
            <input type="checkbox" checked data-cmpcal-filter="<?= h($type['key']) ?>">
            <span class="cmpcal-svg" data-cmpcal-icon="<?= h($type['icon']) ?>"></span>
            <span class="cmpcal-type-pill"><?= h($type['label']) ?></span>
            <span class="cmpcal-count"><?= $count ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="cmpcal-legend">
        <div class="cmpcal-title">Legend</div>
        <div class="cmpcal-legend-row"><span class="cmpcal-legend-swatch is-dashed"></span>Awaiting Approval</div>
        <div class="cmpcal-legend-row"><span class="cmpcal-legend-swatch"></span>Approved</div>
        <div class="cmpcal-legend-row"><span class="cmpcal-svg" data-cmpcal-icon="lock"></span>Locked</div>
        <div class="cmpcal-legend-row"><span class="cmpcal-legend-swatch is-red"></span>Overdue</div>
        <div class="cmpcal-legend-row"><span class="cmpcal-legend-swatch is-yellow"></span>Awaiting Response</div>
      </div>
      <?php
      $missing = array();
      foreach ($sources as $label => $connected) {
          if (!$connected) { $missing[] = $label; }
      }
      ?>
      <?php if ($missing): ?>
        <div class="cmpcal-source-empty">
          <strong>No connected data source yet:</strong>
          <?= h(implode(', ', $missing)) ?>.
        </div>
      <?php endif; ?>
      <?php if (!$tableStatus['events'] || !$tableStatus['change_requests']): ?>
        <div class="cmpcal-source-empty is-warn">
          <strong>Calendar wiring tables not installed yet.</strong>
          Apply <code>scripts/sql/compliance_os_calendar_wiring.sql</code> before saving manual events or change requests.
        </div>
      <?php endif; ?>
    </section>
  </aside>

  <section class="cmpcal-card">
    <div class="cmpcal-toolbar">
      <div class="cmpcal-toolbar-left">
        <button type="button" id="cmpcalPrev">Previous</button>
        <button type="button" id="cmpcalToday">Today</button>
        <button type="button" id="cmpcalNext">Next</button>
        <div class="cmpcal-range" id="cmpcalRange">Schedule</div>
      </div>
      <div class="cmpcal-toolbar-right">
        <select class="cmpcal-select" id="cmpcalViewSelect" aria-label="Schedule view">
          <option value="month">Month View</option>
          <option value="week">Week View</option>
          <option value="day">Day View</option>
        </select>
        <select class="cmpcal-select" id="cmpcalTimezoneSelect" aria-label="Timezone"></select>
      </div>
    </div>
    <div class="cmpcal-main">
      <div class="cmpcal-view is-active" id="cmpcalMonthView"></div>
      <div class="cmpcal-view" id="cmpcalWeekView"></div>
      <div class="cmpcal-view" id="cmpcalDayView"></div>
    </div>
  </section>
</div>

<section class="cmpcal-card cmpcal-card-pad cmpcal-queue">
  <div class="cmpcal-head">
    <div>
      <h2 class="cmpcal-title">Pending Calendar Change Requests</h2>
      <div class="cmpcal-muted">Governed moves and locked-source changes wait here before any source deadline can be changed.</div>
    </div>
    <span class="cmpcal-count"><?= count($pendingRequests) ?></span>
  </div>
  <?php if ($pendingRequests === array()): ?>
    <div class="cmpcal-empty-panel">No pending calendar change requests.</div>
  <?php else: ?>
    <div class="cmpcal-queue-list">
      <?php foreach ($pendingRequests as $request): ?>
        <article class="cmpcal-queue-item">
          <div class="cmpcal-queue-top">
            <div>
              <div class="cmpcal-queue-title"><?= h((string)$request['title']) ?></div>
              <div class="cmpcal-queue-meta">
                <?= h((string)$request['source_event_id']) ?> ·
                <?= h((string)$request['change_kind']) ?> ·
                <?= h((string)$request['current_starts_at']) ?> → <?= h((string)$request['proposed_starts_at']) ?>
              </div>
              <?php if (!empty($request['reason'])): ?>
                <div class="cmpcal-queue-meta">Reason: <?= h((string)$request['reason']) ?></div>
              <?php endif; ?>
            </div>
            <span class="cmpcal-type-pill">Awaiting approval</span>
          </div>
          <form method="post" class="cmpcal-queue-actions">
            <input type="hidden" name="action" value="review_change_request">
            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
            <input type="hidden" name="return_date" data-cmpcal-return-date>
            <input type="hidden" name="return_view" data-cmpcal-return-view>
            <input type="hidden" name="return_scroll" data-cmpcal-return-scroll>
            <textarea name="reviewer_notes" placeholder="Reviewer notes"></textarea>
            <button type="submit" name="decision" value="approved">Approve</button>
            <button type="submit" name="decision" value="rejected" class="cmp-btn-secondary">Reject</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="cmpcal-tooltip" id="cmpcalTooltip"></div>

<?php compliance_modal_open('calendarEventViewModal', 'Event details'); ?>
  <dl class="cmpcal-event-detail" id="cmpcalEventDetails"></dl>
  <div class="cmpcal-modal-note cmpcal-warning" id="cmpcalEventGovernanceWarning" hidden>
    This appears to be a governed compliance deadline. Moving or deleting this event may require authority/internal approval and deadline-extension logging.
  </div>
  <div class="compliance-modal__footer">
    <button type="button" class="cmp-btn-secondary" id="cmpcalEditEvent">Edit Event</button>
    <button type="button" class="cmp-btn-secondary" id="cmpcalLinkEvent">Link To</button>
    <a class="cmpcal-footer-link" id="cmpcalOpenLinked" href="#" aria-disabled="true">Open Linked Record</a>
    <form method="post" id="cmpcalDeleteEventForm" style="display:inline;">
      <input type="hidden" name="action" value="delete_manual_event">
      <input type="hidden" name="calendar_event_id" id="cmpcalDeleteEventId">
      <input type="hidden" name="return_date" data-cmpcal-return-date>
      <input type="hidden" name="return_view" data-cmpcal-return-view>
      <input type="hidden" name="return_scroll" data-cmpcal-return-scroll>
      <button type="submit" class="cmp-btn-secondary" id="cmpcalDeleteEvent">Delete Event</button>
    </form>
    <button type="button" data-compliance-modal-close>Close</button>
  </div>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('calendarNewEventModal', 'New event'); ?>
  <form id="cmpcalNewEventForm" method="post">
    <input type="hidden" name="action" value="create_manual_event">
    <input type="hidden" name="return_date" data-cmpcal-return-date>
    <input type="hidden" name="return_view" data-cmpcal-return-view>
    <input type="hidden" name="return_scroll" data-cmpcal-return-scroll>
    <div class="cmpcal-form-grid">
      <label class="cmpcal-field"><span>Title</span><input name="title" id="cmpcalNewTitle" placeholder="Compliance event title"></label>
      <label class="cmpcal-field"><span>Event type</span><select name="event_type" id="cmpcalNewType">
        <?php foreach ($eventTypes as $type): ?><option value="<?= h($type['key']) ?>"><?= h($type['label']) ?></option><?php endforeach; ?>
      </select></label>
      <label class="cmpcal-field"><span>Date</span><input type="date" name="date" id="cmpcalNewDate"></label>
      <label class="cmpcal-field" id="cmpcalNewStartField"><span>Start time</span><input type="text" name="start_time" id="cmpcalNewStart" inputmode="text" autocomplete="off"></label>
      <label class="cmpcal-field" id="cmpcalNewEndField"><span>End time</span><input type="text" name="end_time" id="cmpcalNewEnd" inputmode="text" autocomplete="off"></label>
      <label class="cmpcal-field"><span>Timezone</span><select name="timezone" id="cmpcalNewTimezone"></select></label>
      <label class="cmpcal-field"><span id="cmpcalNewLinkedTypeLabel">Type</span><select name="linked_object_type" id="cmpcalNewLinkedType">
        <option value="">Not linked</option>
        <option value="compliance_case">Case / MoC</option>
        <option value="audit">Audit</option>
        <option value="finding">Finding</option>
        <option value="corrective_action">Corrective Action / CAP</option>
        <option value="meeting">Meeting</option>
        <option value="manual_change_request">Manual Change Request</option>
        <option value="regulatory_review">Regulatory Review</option>
      </select></label>
      <label class="cmpcal-field"><span id="cmpcalNewLinkedDetailsLabel">Details</span><select name="linked_object_id" id="cmpcalNewLinkedId"><option value="">Select a type first</option></select></label>
      <label class="cmpcal-field"><span>All-day</span><select name="all_day" id="cmpcalNewAllDay"><option value="1">Yes</option><option value="0">No</option></select></label>
    </div>
    <label class="cmpcal-field" style="display:block;margin-top:12px;"><span>Description</span><textarea name="description" placeholder="Governance context, linked record or approval notes"></textarea></label>
    <div class="cmpcal-modal-note">Manual events are stored in the calendar projection table. Existing compliance source records are not duplicated.</div>
    <div class="compliance-modal__footer">
      <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" <?= $tableStatus['events'] ? '' : 'disabled' ?>>Save Manual Event</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('calendarEditEventModal', 'Edit manual event'); ?>
  <form id="cmpcalEditEventForm" method="post">
    <input type="hidden" name="action" value="update_manual_event">
    <input type="hidden" name="calendar_event_id" id="cmpcalEditEventId">
    <input type="hidden" name="return_date" data-cmpcal-return-date>
    <input type="hidden" name="return_view" data-cmpcal-return-view>
    <input type="hidden" name="return_scroll" data-cmpcal-return-scroll>
    <div class="cmpcal-form-grid">
      <label class="cmpcal-field"><span>Title</span><input name="title" id="cmpcalEditTitle" placeholder="Compliance event title"></label>
      <label class="cmpcal-field"><span>Event type</span><select name="event_type" id="cmpcalEditType">
        <?php foreach ($eventTypes as $type): ?><option value="<?= h($type['key']) ?>"><?= h($type['label']) ?></option><?php endforeach; ?>
      </select></label>
      <label class="cmpcal-field"><span>Date</span><input type="date" name="date" id="cmpcalEditDate"></label>
      <label class="cmpcal-field" id="cmpcalEditStartField"><span>Start time</span><input type="text" name="start_time" id="cmpcalEditStart" inputmode="text" autocomplete="off"></label>
      <label class="cmpcal-field" id="cmpcalEditEndField"><span>End time</span><input type="text" name="end_time" id="cmpcalEditEnd" inputmode="text" autocomplete="off"></label>
      <label class="cmpcal-field"><span>Timezone</span><select name="timezone" id="cmpcalEditTimezone"></select></label>
      <label class="cmpcal-field"><span id="cmpcalEditLinkedTypeLabel">Type</span><select name="linked_object_type" id="cmpcalEditLinkedType">
        <option value="">Not linked</option>
        <option value="compliance_case">Case / MoC</option>
        <option value="audit">Audit</option>
        <option value="finding">Finding</option>
        <option value="corrective_action">Corrective Action / CAP</option>
        <option value="meeting">Meeting</option>
        <option value="manual_change_request">Manual Change Request</option>
        <option value="regulatory_review">Regulatory Review</option>
      </select></label>
      <label class="cmpcal-field"><span id="cmpcalEditLinkedDetailsLabel">Details</span><select name="linked_object_id" id="cmpcalEditLinkedId"><option value="">Select a type first</option></select></label>
      <label class="cmpcal-field"><span>All-day</span><select name="all_day" id="cmpcalEditAllDay"><option value="1">Yes</option><option value="0">No</option></select></label>
    </div>
    <label class="cmpcal-field" style="display:block;margin-top:12px;"><span>Description</span><textarea name="description" id="cmpcalEditDescription" placeholder="Governance context, linked record or approval notes"></textarea></label>
    <div class="cmpcal-modal-note">This edits the manual calendar event record. Source-projected compliance records still use their source workflows or schedule-change requests.</div>
    <div class="compliance-modal__footer">
      <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" <?= $tableStatus['events'] ? '' : 'disabled' ?>>Save Event</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('calendarLinkEventModal', 'Link To'); ?>
  <form id="cmpcalLinkEventForm" method="post">
    <input type="hidden" name="action" value="link_manual_event">
    <input type="hidden" name="calendar_event_id" id="cmpcalLinkEventId">
    <input type="hidden" name="return_date" data-cmpcal-return-date>
    <input type="hidden" name="return_view" data-cmpcal-return-view>
    <input type="hidden" name="return_scroll" data-cmpcal-return-scroll>
    <div class="cmpcal-form-grid">
      <label class="cmpcal-field"><span id="cmpcalLinkTypeLabel">Type</span><select name="linked_object_type" id="cmpcalLinkType">
        <option value="">Not linked</option>
        <option value="compliance_case">Case / MoC</option>
        <option value="audit">Audit</option>
        <option value="finding">Finding</option>
        <option value="corrective_action">Corrective Action / CAP</option>
        <option value="meeting">Meeting</option>
        <option value="manual_change_request">Manual Change Request</option>
        <option value="regulatory_review">Regulatory Review</option>
      </select></label>
      <label class="cmpcal-field"><span id="cmpcalLinkDetailsLabel">Details</span><select name="linked_object_id" id="cmpcalLinkId"><option value="">Select a type first</option></select></label>
    </div>
    <div class="cmpcal-modal-note">Links are stored on the manual calendar event only. The linked compliance record remains owned by its source page.</div>
    <div class="compliance-modal__footer">
      <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" <?= $tableStatus['events'] ? '' : 'disabled' ?>>Save Link</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('calendarSettingsModal', 'Schedule settings'); ?>
  <p class="cmpcal-muted" style="margin-top:0;">Choose favorite timezones to show in the schedule toolbar. Stored event times are not mutated.</p>
  <div class="cmpcal-settings-list" id="cmpcalFavoriteTimezoneList"></div>
  <div class="cmpcal-title" style="margin-top:16px;">Time Format</div>
  <div class="cmpcal-settings-list" id="cmpcalTimeFormatList">
    <label><input type="radio" name="cmpcal_time_format" value="24"> 24-hour time</label>
    <label><input type="radio" name="cmpcal_time_format" value="12"> 12-hour time (AM/PM)</label>
  </div>
  <div class="cmpcal-modal-note">Favorites are stored locally in this browser for UI confirmation only.</div>
  <div class="compliance-modal__footer">
    <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
    <button type="button" id="cmpcalSaveTimezoneSettings">Apply favorites</button>
  </div>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('calendarConfirmChangeModal', 'Confirm schedule change'); ?>
  <form method="post" id="cmpcalConfirmChangeForm">
    <input type="hidden" name="action" value="calendar_change">
    <input type="hidden" name="return_date" data-cmpcal-return-date>
    <input type="hidden" name="return_view" data-cmpcal-return-view>
    <input type="hidden" name="return_scroll" data-cmpcal-return-scroll>
    <input type="hidden" name="event_id" id="cmpcalChangeEventId">
    <input type="hidden" name="proposed_starts_at" id="cmpcalChangeStartsAt">
    <input type="hidden" name="proposed_ends_at" id="cmpcalChangeEndsAt">
    <dl class="cmpcal-event-detail" id="cmpcalConfirmDetails"></dl>
    <div class="cmpcal-modal-note cmpcal-warning" id="cmpcalDeadlineMoveWarning" hidden>
      This appears to be a governed compliance deadline. Moving this event may require authority/internal approval and deadline-extension logging.
    </div>
    <label class="cmpcal-field" style="display:block;margin-top:12px;">
      <span>Reason / approval note</span>
      <textarea name="reason" id="cmpcalChangeReason" placeholder="Reason for the proposed schedule change"></textarea>
    </label>
    <div class="cmpcal-modal-note" id="cmpcalChangeModeNote">Unlocked meetings and manual events can be updated directly. Governed items create a pending approval request.</div>
    <div class="compliance-modal__footer">
      <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" <?= $tableStatus['change_requests'] ? '' : 'disabled' ?>>Confirm Change</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<script>
(function () {
  var events = <?= json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;
  var typeDefs = <?= json_encode($eventTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;
  var linkableGroups = <?= json_encode($linkableGroups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;
  var iconMap = {
    calendar:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7Zm0 4h14M9 4v3m6-3v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    clock:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    hourglass:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10M7 20h10M8 4c0 4 8 4 8 8s-8 4-8 8M16 4c0 4-8 4-8 8s8 4 8 8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    shield:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.4-2.7 8.4-7 10c-4.3-1.6-7-5.6-7-10V6l7-3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    users:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a3 3 0 1 0 0-6a3 3 0 0 0 0 6Zm6 7c0-1.6-.8-3-2-3.7M16 6.4a2.5 2.5 0 0 1 0 4.2M6 19c0-1.6.8-3 2-3.7M8 6.4a2.5 2.5 0 0 0 0 4.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    wrench:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6a4 4 0 0 1 5.5 5.5l-9 9l-3-3l9-9A4 4 0 0 1 14 6Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'clipboard-check':'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5h6M9 4h6v3H9V4ZM7 6H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-1M8 14l2.5 2.5L16 11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    scale:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M5 6h14M7 6l-4 7h8L7 6Zm10 0l-4 7h8l-4-7ZM9 21h6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    book:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4Zm0 13a3 3 0 0 1 3-3h11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    lock:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2M6 10h12v10H6V10Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    alert:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3L12 4Zm0 5v5m0 3h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    circle:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>'
  };
  var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  var tzChoices = [
    {key:'browser', label:'Browser Local', zone:browserTz},
    {key:'belgium', label:'Belgium', zone:'Europe/Brussels'},
    {key:'california', label:'California', zone:'America/Los_Angeles'},
    {key:'utc', label:'UTC', zone:'UTC'},
    {key:'london', label:'London', zone:'Europe/London'},
    {key:'new_york', label:'New York', zone:'America/New_York'},
    {key:'tokyo', label:'Tokyo', zone:'Asia/Tokyo'}
  ];
  var state = {
    current: startOfDay(new Date()),
    selected: startOfDay(new Date()),
    mini: startOfMonth(new Date()),
    view: localStorage.getItem('ipcaComplianceCalendarView') || 'month',
    timezone: localStorage.getItem('ipcaComplianceCalendarTimezone') || browserTz,
    favorites: JSON.parse(localStorage.getItem('ipcaComplianceCalendarFavoriteTimezones') || '["browser","belgium","california","utc"]'),
    timeFormat: localStorage.getItem('ipcaComplianceCalendarTimeFormat') || '24',
    draggingEventId: '',
    dragGhost: null,
    pendingVisualChange: false,
    suppressEventClick: false,
    suppressScheduleClick: false,
    selectedEvent: null,
    activeTypes: new Set(typeDefs.map(function (t) { return t.key; }))
  };
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('date') && /^\d{4}-\d{2}-\d{2}$/.test(urlParams.get('date'))) {
    state.current = parseYmd(urlParams.get('date'));
    state.selected = parseYmd(urlParams.get('date'));
    state.mini = startOfMonth(state.current);
  }
  if (['month','week','day'].indexOf(urlParams.get('view')) !== -1) {
    state.view = urlParams.get('view');
  }
  if (state.timeFormat !== '12') {
    state.timeFormat = '24';
  }
  var initialScroll = parseInt(urlParams.get('scroll') || '0', 10);
  var restoredInitialScroll = false;

  document.querySelectorAll('[data-cmpcal-icon]').forEach(function (el) {
    el.innerHTML = iconMap[el.getAttribute('data-cmpcal-icon')] || iconMap.circle;
  });

  function pad(n){ return String(n).padStart(2,'0'); }
  function ymd(d){ return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
  function parseDt(v){ return new Date(String(v || '').replace(' ', 'T')); }
  function parseYmd(v){ var bits = String(v).split('-').map(Number); return new Date(bits[0], bits[1] - 1, bits[2]); }
  function startOfDay(d){ return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
  function addDays(d,n){ var x = new Date(d); x.setDate(x.getDate()+n); return x; }
  function addMonths(d,n){ var x = new Date(d); x.setMonth(x.getMonth()+n); return x; }
  function mondayStart(d){ var x = startOfDay(d); var day = (x.getDay()+6)%7; return addDays(x, -day); }
  function sameDay(a,b){ return ymd(a) === ymd(b); }
  function monthName(d){ return d.toLocaleDateString('en-GB',{month:'long',year:'numeric'}); }
  function fmtRange(d){ return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
  function fmtTime(dt){
    var h = dt.getHours();
    var m = pad(dt.getMinutes());
    if (state.timeFormat === '12') {
      var suffix = h >= 12 ? 'PM' : 'AM';
      var hour12 = h % 12;
      if (hour12 === 0) { hour12 = 12; }
      return hour12 + ':' + m + ' ' + suffix;
    }
    return pad(h) + ':' + m;
  }
  function fmtDateTime(dt){
    return fmtRange(dt) + ' ' + fmtTime(dt);
  }
  function postDateTime(dt){
    return dt.getFullYear() + '-' + pad(dt.getMonth()+1) + '-' + pad(dt.getDate()) + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ':00';
  }
  function movedDateForEvent(ev, day, useEnd){
    var base = parseDt(useEnd ? (ev.ends_at || ev.starts_at) : ev.starts_at);
    var d = startOfDay(day);
    if (ev.is_all_day) {
      d.setHours(useEnd ? 23 : 0, useEnd ? 59 : 0, useEnd ? 59 : 0, 0);
    } else {
      d.setHours(base.getHours(), base.getMinutes(), 0, 0);
    }
    return d;
  }
  function dueText(ev){
    var diff = Math.round((startOfDay(parseDt(ev.starts_at)) - startOfDay(new Date())) / 86400000);
    if (diff < 0) { return Math.abs(diff) + ' days overdue'; }
    if (diff === 0) { return 'due today'; }
    return 'due in ' + diff + ' days';
  }
  function eventTouchesDate(ev, d){
    var day = ymd(d);
    return day >= ymd(parseDt(ev.starts_at)) && day <= ymd(parseDt(ev.ends_at || ev.starts_at));
  }
  function clamp(n, min, max){ return Math.max(min, Math.min(max, n)); }
  function snapMinutes(minutes){ return clamp(Math.round(minutes / 15) * 15, 0, 1439); }
  function minutesFromPointer(e, col){
    var rect = col.getBoundingClientRect();
    return snapMinutes(((e.clientY - rect.top) / rect.height) * 1440);
  }
  function dateWithMinutes(day, minutes){
    var d = startOfDay(day);
    d.setMinutes(clamp(minutes, 0, 1439));
    return d;
  }
  function minutesFromClientY(clientY, col){
    var rect = col.getBoundingClientRect();
    return snapMinutes(((clientY - rect.top) / rect.height) * 1440);
  }
  function addMinutes(d, minutes){
    var x = new Date(d);
    x.setMinutes(x.getMinutes() + minutes);
    return x;
  }
  function durationMinutes(ev){
    var s = parseDt(ev.starts_at);
    var e = parseDt(ev.ends_at || ev.starts_at);
    return Math.max(15, Math.round((e - s) / 60000));
  }
  function formatTimeInput(hour, minute){
    var dt = new Date(2000, 0, 1, hour, minute || 0, 0);
    return fmtTime(dt);
  }
  function normalizeTimeText(value){
    var v = String(value || '').trim().toUpperCase().replace(/\s+/g, ' ');
    var m = v.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/);
    if (m) {
      var h12 = parseInt(m[1], 10);
      var mm12 = parseInt(m[2], 10);
      if (h12 < 1 || h12 > 12 || mm12 > 59) { return ''; }
      var h24 = h12 % 12;
      if (m[3] === 'PM') { h24 += 12; }
      return pad(h24) + ':' + pad(mm12);
    }
    m = v.match(/^(\d{1,2}):(\d{2})$/);
    if (m) {
      var h = parseInt(m[1], 10);
      var mm = parseInt(m[2], 10);
      if (h > 23 || mm > 59) { return ''; }
      return pad(h) + ':' + pad(mm);
    }
    return '';
  }
  function currentEventWindowLabel(ev){
    if (ev.is_all_day) {
      return fmtRange(parseDt(ev.starts_at)) + ' all day';
    }
    return fmtDateTime(parseDt(ev.starts_at)) + ' - ' + fmtTime(parseDt(ev.ends_at || ev.starts_at));
  }
  function proposedEventWindowLabel(start, end, isAllDay){
    if (isAllDay) {
      return fmtRange(start) + ' all day';
    }
    return fmtDateTime(start) + ' - ' + fmtTime(end);
  }
  function updateAllDayFields(){
    var allDay = document.getElementById('cmpcalNewAllDay').value === '1';
    ['cmpcalNewStart', 'cmpcalNewEnd'].forEach(function(id){
      var input = document.getElementById(id);
      input.disabled = allDay;
      input.required = !allDay;
      input.closest('.cmpcal-field').classList.toggle('is-disabled', allDay);
    });
  }
  function updateEditAllDayFields(){
    var allDay = document.getElementById('cmpcalEditAllDay').value === '1';
    ['cmpcalEditStart', 'cmpcalEditEnd'].forEach(function(id){
      var input = document.getElementById(id);
      input.disabled = allDay;
      input.required = !allDay;
      input.closest('.cmpcal-field').classList.toggle('is-disabled', allDay);
    });
  }
  function closeDialog(id){
    var d = document.getElementById(id);
    if (!d) { return; }
    if (typeof d.close === 'function') { d.close(); } else { d.removeAttribute('open'); }
  }
  function suppressNativeDragImage(e){
    if (!e.dataTransfer || typeof e.dataTransfer.setDragImage !== 'function') { return; }
    try {
      var img = document.createElement('canvas');
      img.width = 1;
      img.height = 1;
      img.style.position = 'fixed';
      img.style.left = '-10px';
      img.style.top = '-10px';
      img.style.opacity = '0';
      document.body.appendChild(img);
      e.dataTransfer.setDragImage(img, 0, 0);
      setTimeout(function(){
        if (img.parentNode) { img.parentNode.removeChild(img); }
      }, 0);
    } catch (err) {
      // Keep native browser dragging if the custom drag image is not supported.
    }
  }
  function createDragGhost(ev){
    removeDragGhost();
    var ghost = document.createElement('div');
    ghost.className = 'cmpcal-drag-ghost cmpcal-type-' + (ev.color_key || ev.event_type || 'other');
    ghost.innerHTML = '<div>' + iconForEvent(ev) + ' ' + escapeHtml(ev.title) + '</div><span class="time">' + escapeHtml(currentEventWindowLabel(ev)) + '</span>';
    document.body.appendChild(ghost);
    state.dragGhost = ghost;
  }
  function moveDragGhost(e){
    if (!state.dragGhost) { return; }
    state.dragGhost.style.left = (e.clientX + 16) + 'px';
    state.dragGhost.style.top = (e.clientY + 16) + 'px';
  }
  function updateDragGhostTime(start, end){
    if (!state.dragGhost) { return; }
    var time = state.dragGhost.querySelector('.time');
    if (time) { time.textContent = fmtDateTime(start) + ' - ' + fmtTime(end); }
  }
  function removeDragGhost(){
    if (state.dragGhost && state.dragGhost.parentNode) {
      state.dragGhost.parentNode.removeChild(state.dragGhost);
    }
    state.dragGhost = null;
  }
  function consumeSuppressedScheduleClick(e){
    if (!state.suppressScheduleClick) { return false; }
    e.preventDefault();
    e.stopPropagation();
    state.suppressScheduleClick = false;
    return true;
  }
  function droppedEventId(e){
    var id = '';
    if (e.dataTransfer && typeof e.dataTransfer.getData === 'function') {
      id = e.dataTransfer.getData('text/plain');
    }
    return id || state.draggingEventId;
  }
  function linkOptionsForType(type){
    var group = linkableGroups.find(function(item){ return item.type === type; });
    return group && Array.isArray(group.options) ? group.options : [];
  }
  function normalizeLinkModalLabels(){
    ['cmpcalNewLinkedTypeLabel', 'cmpcalEditLinkedTypeLabel', 'cmpcalLinkTypeLabel'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) { el.textContent = 'Type'; }
    });
    ['cmpcalNewLinkedDetailsLabel', 'cmpcalEditLinkedDetailsLabel', 'cmpcalLinkDetailsLabel'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) { el.textContent = 'Details'; }
    });
  }
  function populateLinkedDetails(selectId, type, selectedId, selectedLabel){
    normalizeLinkModalLabels();
    var sel = document.getElementById(selectId);
    if (!sel) { return; }
    sel.innerHTML = '';
    if (!type) {
      sel.disabled = true;
      sel.innerHTML = '<option value="">No linked record</option>';
      return;
    }
    sel.disabled = false;
    var options = linkOptionsForType(type);
    if (options.length === 0) {
      sel.innerHTML = '<option value="">No records available for this type</option>';
      return;
    }
    sel.appendChild(new Option('Select details', ''));
    options.forEach(function(option){
      sel.appendChild(new Option(option.label || ('Record #' + option.id), option.id));
    });
    if (selectedId && !options.some(function(option){ return String(option.id) === String(selectedId); })) {
      sel.appendChild(new Option(selectedLabel || ('Current linked record #' + selectedId), String(selectedId)));
    }
    sel.value = selectedId ? String(selectedId) : '';
  }
  function populateLinkDetails(type, selectedId, selectedLabel){
    populateLinkedDetails('cmpcalLinkId', type, selectedId, selectedLabel);
  }
  function validateLinkedDetails(typeId, detailsId){
    var type = document.getElementById(typeId).value;
    var details = document.getElementById(detailsId);
    var id = details ? details.value : '';
    if (type !== '' && (!id || parseInt(id, 10) <= 0)) {
      alert('Choose details, or set Type to Not linked to clear the link.');
      return false;
    }
    if (type === '' && details) {
      details.value = '';
    }
    return true;
  }
  function timeColumnFromPoint(clientX, clientY){
    var el = document.elementFromPoint(clientX, clientY);
    return el ? el.closest('.cmpcal-time-col') : null;
  }
  function visibleEvents(){
    return events.filter(function (ev) { return state.activeTypes.has(ev.event_type || 'other'); });
  }
  function classesForEvent(ev){
    var cls = 'cmpcal-event cmpcal-type-' + (ev.color_key || ev.event_type || 'other');
    if (ev.is_pending_approval || ev.governance_state === 'pending_approval' || ev.governance_state === 'pending') { cls += ' is-pending'; }
    if (ev.is_overdue) { cls += ' is-overdue'; }
    if (ev.governance_state === 'awaiting_response') { cls += ' is-awaiting'; }
    if (ev.is_locked) { cls += ' is-locked'; }
    return cls;
  }
  function iconForEvent(ev){
    if (ev.is_overdue) { return iconMap.alert; }
    if (ev.is_pending_approval || ev.governance_state === 'awaiting_response') { return iconMap.hourglass; }
    if (ev.is_locked && String(ev.event_type || '').indexOf('deadline') !== -1) { return iconMap.lock; }
    return iconMap[ev.icon_key] || iconMap.circle;
  }
  function eventPill(ev){
    var btn = document.createElement('div');
    btn.setAttribute('role', 'button');
    btn.setAttribute('tabindex', '0');
    btn.className = classesForEvent(ev);
    btn.draggable = true;
    btn.dataset.eventId = ev.id;
    btn.innerHTML = iconForEvent(ev) + '<span>' + escapeHtml(ev.title) + '</span>';
    btn.addEventListener('click', function (e) { e.stopPropagation(); openEventModal(ev); });
    btn.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openEventModal(ev); } });
    btn.addEventListener('dragstart', function (e) {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', ev.id);
      suppressNativeDragImage(e);
      state.draggingEventId = ev.id;
      createDragGhost(ev);
      moveDragGhost(e);
      btn.classList.add('is-dragging');
    });
    btn.addEventListener('dragend', function () { state.draggingEventId = ''; btn.classList.remove('is-dragging'); removeDragGhost(); });
    btn.addEventListener('mouseenter', function (e) { showTooltip(ev, e); });
    btn.addEventListener('mousemove', moveTooltip);
    btn.addEventListener('mouseleave', hideTooltip);
    return btn;
  }
  function escapeHtml(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});
  }
  function showDialog(id){
    var d = document.getElementById(id);
    if (!d) { return; }
    if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open','open'); }
  }
  function setNewModalDate(d, hour){
    document.getElementById('cmpcalNewDate').value = ymd(d);
    document.getElementById('cmpcalNewStart').value = hour != null ? formatTimeInput(hour, 0) : formatTimeInput(9, 0);
    document.getElementById('cmpcalNewEnd').value = hour != null ? formatTimeInput(Math.min(23, hour + 1), 0) : formatTimeInput(10, 0);
    document.getElementById('cmpcalNewStart').placeholder = state.timeFormat === '12' ? '9:00 AM' : '09:00';
    document.getElementById('cmpcalNewEnd').placeholder = state.timeFormat === '12' ? '10:00 AM' : '10:00';
    document.getElementById('cmpcalNewAllDay').value = '1';
    updateAllDayFields();
    document.getElementById('cmpcalNewTimezone').innerHTML = timezoneOptionsHtml();
    document.getElementById('cmpcalNewLinkedType').value = '';
    populateLinkedDetails('cmpcalNewLinkedId', '', '', '');
    showDialog('calendarNewEventModal');
  }
  function openEditManualModal(ev){
    if (!ev || String(ev.id).indexOf('manual:') !== 0 || !ev.can_edit_directly || ev.is_locked) {
      alert('Only unlocked manual calendar events can be edited here. Source records use their source workflow or schedule-change request.');
      return;
    }
    var start = parseDt(ev.starts_at);
    var end = parseDt(ev.ends_at || ev.starts_at);
    document.getElementById('cmpcalEditEventId').value = String(ev.id).replace('manual:', '');
    document.getElementById('cmpcalEditTitle').value = ev.title || '';
    document.getElementById('cmpcalEditType').value = ev.event_type || 'other';
    document.getElementById('cmpcalEditDate').value = ymd(start);
    document.getElementById('cmpcalEditStart').value = formatTimeInput(start.getHours(), start.getMinutes());
    document.getElementById('cmpcalEditEnd').value = formatTimeInput(end.getHours(), end.getMinutes());
    document.getElementById('cmpcalEditStart').placeholder = state.timeFormat === '12' ? '9:00 AM' : '09:00';
    document.getElementById('cmpcalEditEnd').placeholder = state.timeFormat === '12' ? '10:00 AM' : '10:00';
    document.getElementById('cmpcalEditTimezone').innerHTML = timezoneOptionsHtml();
    if (ev.timezone && !Array.from(document.getElementById('cmpcalEditTimezone').options).some(function(option){ return option.value === ev.timezone; })) {
      var option = document.createElement('option');
      option.value = ev.timezone;
      option.textContent = 'Event timezone (' + ev.timezone + ')';
      document.getElementById('cmpcalEditTimezone').appendChild(option);
    }
    document.getElementById('cmpcalEditTimezone').value = ev.timezone || state.timezone;
    if (!document.getElementById('cmpcalEditTimezone').value) {
      document.getElementById('cmpcalEditTimezone').value = state.timezone;
    }
    document.getElementById('cmpcalEditLinkedType').value = ev.linked_object_type || '';
    populateLinkedDetails('cmpcalEditLinkedId', ev.linked_object_type || '', ev.linked_object_id ? String(ev.linked_object_id) : '', '');
    document.getElementById('cmpcalEditAllDay').value = ev.is_all_day ? '1' : '0';
    document.getElementById('cmpcalEditDescription').value = ev.description || '';
    updateEditAllDayFields();
    closeDialog('calendarEventViewModal');
    showDialog('calendarEditEventModal');
  }
  function openLinkManualModal(ev){
    if (!ev || String(ev.id).indexOf('manual:') !== 0 || !ev.can_edit_directly || ev.is_locked) {
      alert('Only unlocked manual calendar events can be linked here. Source-projected compliance events are already linked to their source records.');
      return;
    }
    normalizeLinkModalLabels();
    document.getElementById('cmpcalLinkEventId').value = String(ev.id).replace('manual:', '');
    document.getElementById('cmpcalLinkType').value = ev.linked_object_type || '';
    populateLinkDetails(ev.linked_object_type || '', ev.linked_object_id ? String(ev.linked_object_id) : '', '');
    closeDialog('calendarEventViewModal');
    showDialog('calendarLinkEventModal');
  }
  function openEventModal(ev){
    state.selectedEvent = ev;
    var details = document.getElementById('cmpcalEventDetails');
    var metadata = ev.metadata || {};
    var linked = ev.linked_object_type ? ev.linked_object_type + ' #' + (ev.linked_object_id || ev.source_id || '') : '';
    details.innerHTML = detailRows({
      'Event title': ev.title,
      'Event type': labelForType(ev.event_type),
      'Status': ev.status || 'Not set',
      'Governance state': ev.governance_state || 'Not set',
      'Date': fmtDateTime(parseDt(ev.starts_at)),
      'Start time': ev.is_all_day ? 'All day' : fmtTime(parseDt(ev.starts_at)),
      'End time': ev.is_all_day ? 'All day' : fmtTime(parseDt(ev.ends_at || ev.starts_at)),
      'Timezone': state.timezone,
      'Linked object type': ev.linked_object_type || 'Not linked',
      'Linked object reference': linked || metadata.code || 'Not linked',
      'Description': ev.description || 'No description',
      'Source table/source object': (ev.source_table || 'No connected data source yet') + ' / ' + (ev.source_id || ''),
      'Created by': ev.created_by || 'Not available',
      'Updated by': ev.updated_by || 'Not available'
    });
    document.getElementById('cmpcalEventGovernanceWarning').hidden = !(ev.is_locked || ev.requires_approval_to_move);
    var canEditManual = String(ev.id).indexOf('manual:') === 0 && ev.can_edit_directly && !ev.is_locked;
    var edit = document.getElementById('cmpcalEditEvent');
    edit.disabled = !canEditManual;
    edit.textContent = canEditManual ? 'Edit Event' : 'Edit Manual Events Only';
    var link = document.getElementById('cmpcalLinkEvent');
    link.disabled = !canEditManual;
    link.textContent = 'Link To';
    var open = document.getElementById('cmpcalOpenLinked');
    if (metadata.linked_url) {
      open.href = metadata.linked_url;
      open.removeAttribute('aria-disabled');
      open.classList.add('is-active');
    } else {
      open.href = '#';
      open.setAttribute('aria-disabled','true');
      open.classList.remove('is-active');
    }
    document.getElementById('cmpcalDeleteEvent').disabled = !ev.can_delete || String(ev.id).indexOf('manual:') !== 0;
    document.getElementById('cmpcalDeleteEventId').value = String(ev.id).indexOf('manual:') === 0 ? String(ev.id).replace('manual:', '') : '';
    showDialog('calendarEventViewModal');
  }
  function detailRows(obj){
    return Object.keys(obj).map(function (k) { return '<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(obj[k]) + '</dd>'; }).join('');
  }
  function labelForType(key){
    var found = typeDefs.find(function(t){ return t.key === key; });
    return found ? found.label : 'Other Event';
  }
  function showTooltip(ev, e){
    var tip = document.getElementById('cmpcalTooltip');
    var meta = ev.metadata || {};
    tip.innerHTML = '<strong>' + escapeHtml(ev.title) + '</strong>'
      + '<div>' + escapeHtml(meta.authority || ev.governance_state || 'internal') + '</div>'
      + '<div>' + escapeHtml(dueText(ev)) + '</div>'
      + '<div>' + escapeHtml(meta.code || meta.finding || ev.linked_object_type || 'No linked record') + '</div>'
      + '<div>Status: ' + escapeHtml(ev.status || 'Not set') + '</div>';
    tip.style.display = 'block';
    moveTooltip(e);
  }
  function moveTooltip(e){
    var tip = document.getElementById('cmpcalTooltip');
    tip.style.left = (e.clientX + 14) + 'px';
    tip.style.top = (e.clientY + 14) + 'px';
  }
  function hideTooltip(){ document.getElementById('cmpcalTooltip').style.display = 'none'; }

  function renderMini(){
    document.getElementById('cmpcalMiniTitle').textContent = monthName(state.mini);
    var grid = document.getElementById('cmpcalMiniGrid');
    grid.innerHTML = '';
    var start = mondayStart(startOfMonth(state.mini));
    for (var i=0;i<42;i++){
      var d = addDays(start, i);
      var btn = document.createElement('div');
      btn.setAttribute('role', 'button');
      btn.setAttribute('tabindex', '0');
      btn.className = 'cmpcal-mini-day';
      if (d.getMonth() !== state.mini.getMonth()) { btn.className += ' is-out'; }
      if (sameDay(d, new Date())) { btn.className += ' is-today'; }
      if (sameDay(d, state.selected)) { btn.className += ' is-selected'; }
      btn.innerHTML = '<span>' + d.getDate() + '</span>' + (visibleEvents().some(function(ev){return eventTouchesDate(ev,d);}) ? '<i class="cmpcal-dot"></i>' : '');
      btn.addEventListener('click', (function(day){ return function(){ state.selected = day; state.current = day; renderAll(); }; })(d));
      btn.addEventListener('keydown', (function(day){ return function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); state.selected = day; state.current = day; renderAll(); } }; })(d));
      grid.appendChild(btn);
    }
  }
  function renderMonth(){
    var root = document.getElementById('cmpcalMonthView');
    root.innerHTML = '<div class="cmpcal-month-grid" id="cmpcalMonthGrid"></div>';
    var grid = document.getElementById('cmpcalMonthGrid');
    ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].forEach(function (w) {
      var h = document.createElement('div'); h.className = 'cmpcal-month-head'; h.textContent = w; grid.appendChild(h);
    });
    var start = mondayStart(startOfMonth(state.current));
    for (var i=0;i<42;i++){
      var d = addDays(start, i);
      var dayEvents = visibleEvents().filter(function(ev){ return eventTouchesDate(ev,d); });
      var cell = document.createElement('div');
      cell.className = 'cmpcal-month-day';
      if (d.getMonth() !== state.current.getMonth()) { cell.className += ' is-out'; }
      if (sameDay(d, state.selected)) { cell.className += ' is-selected'; }
      if (dayEvents.length > 4) { cell.className += ' is-dense'; }
      cell.innerHTML = '<span class="cmpcal-day-number ' + (sameDay(d,new Date()) ? 'is-today ' : '') + (sameDay(d,state.selected) ? 'is-selected' : '') + '">' + d.getDate() + '</span><div class="cmpcal-events"></div>';
      var list = cell.querySelector('.cmpcal-events');
      dayEvents.slice(0,4).forEach(function(ev){ list.appendChild(eventPill(ev)); });
      if (dayEvents.length > 4) {
        var more = document.createElement('div'); more.className = 'cmpcal-more'; more.textContent = '+' + (dayEvents.length - 4) + ' more'; list.appendChild(more);
      }
      cell.addEventListener('click', (function(day){ return function(e){ if (consumeSuppressedScheduleClick(e)) { return; } state.selected = day; setNewModalDate(day); renderAll(); }; })(d));
      cell.addEventListener('dragover', function(e){ e.preventDefault(); e.dataTransfer.dropEffect = 'move'; cell.classList.add('is-drop-target'); });
      cell.addEventListener('dragleave', function(){ cell.classList.remove('is-drop-target'); });
      cell.addEventListener('drop', (function(day){ return function(e){ e.preventDefault(); cell.classList.remove('is-drop-target'); confirmChange(droppedEventId(e), day); }; })(d));
      grid.appendChild(cell);
    }
  }
  function renderAgenda(kind){
    var isDay = kind === 'day';
    var root = document.getElementById(isDay ? 'cmpcalDayView' : 'cmpcalWeekView');
    var start = isDay ? startOfDay(state.current) : mondayStart(state.current);
    var days = isDay ? [start] : [0,1,2,3,4,5,6].map(function(i){ return addDays(start,i); });
    root.innerHTML = '<div class="cmpcal-week-shell"><div class="cmpcal-week-head ' + (isDay ? 'day' : '') + '"><div></div></div><div class="cmpcal-all-day ' + (isDay ? 'day' : '') + '"><div class="cmpcal-all-day-label">All-day</div></div><div class="cmpcal-timeline ' + (isDay ? 'day' : '') + '"><div class="cmpcal-hours"></div></div></div>';
    var head = root.querySelector('.cmpcal-week-head');
    var allDay = root.querySelector('.cmpcal-all-day');
    var timeline = root.querySelector('.cmpcal-timeline');
    var hours = root.querySelector('.cmpcal-hours');
    for (var h=0;h<24;h++){ var hour = document.createElement('div'); hour.className='cmpcal-hour'; hour.textContent=pad(h)+':00'; hours.appendChild(hour); }
    days.forEach(function(day){
      var label = document.createElement('div');
      label.className = 'cmpcal-week-label' + (sameDay(day,new Date()) ? ' is-today' : '');
      label.innerHTML = day.toLocaleDateString('en-GB',{weekday:'short'}) + ' <span class="num">' + day.getDate() + '</span>';
      head.appendChild(label);
      var ad = document.createElement('div'); ad.className = 'cmpcal-all-day-cell';
      visibleEvents().filter(function(ev){ return ev.is_all_day && eventTouchesDate(ev,day); }).slice(0,4).forEach(function(ev){ ad.appendChild(eventPill(ev)); });
      ad.addEventListener('click', function(e){ if (consumeSuppressedScheduleClick(e)) { return; } setNewModalDate(day); });
      ad.addEventListener('dragover', function(e){ e.preventDefault(); e.dataTransfer.dropEffect = 'move'; ad.classList.add('is-drop-target'); });
      ad.addEventListener('dragleave', function(){ ad.classList.remove('is-drop-target'); });
      ad.addEventListener('drop', function(e){ e.preventDefault(); ad.classList.remove('is-drop-target'); confirmChange(droppedEventId(e), day); });
      allDay.appendChild(ad);
      var col = document.createElement('div'); col.className = 'cmpcal-time-col' + (sameDay(day,new Date()) ? ' is-today' : '');
      col.dataset.day = ymd(day);
      col.addEventListener('click', function(e){
        if (consumeSuppressedScheduleClick(e)) { return; }
        if (e.target !== col) { return; }
        var rect = col.getBoundingClientRect();
        var hour = Math.floor(((e.clientY - rect.top) / rect.height) * 24);
        setNewModalDate(day, Math.max(0, Math.min(23, hour)));
      });
      col.addEventListener('dragover', function(e){
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        col.classList.add('is-drop-target');
        updateDragPreview(e, col, day);
      });
      col.addEventListener('dragleave', function(){ col.classList.remove('is-drop-target'); });
      col.addEventListener('drop', function(e){
        e.preventDefault();
        col.classList.remove('is-drop-target');
        confirmChangeAtMinutes(droppedEventId(e), day, minutesFromPointer(e, col));
      });
      var timed = visibleEvents().filter(function(ev){ return !ev.is_all_day && eventTouchesDate(ev,day); });
      timed.forEach(function(ev, idx){
        var s = parseDt(ev.starts_at), e = parseDt(ev.ends_at || ev.starts_at);
        var sm = s.getHours()*60 + s.getMinutes();
        var em = Math.max(sm + 30, e.getHours()*60 + e.getMinutes());
        var height = Math.max(24, (em-sm) / 1440 * 960);
        var box = document.createElement('div');
        box.className = 'cmpcal-time-event cmpcal-type-' + (ev.color_key || ev.event_type || 'other') + (ev.is_pending_approval ? ' is-pending' : '') + (ev.is_overdue ? ' is-overdue' : '') + (height < 36 ? ' is-compact' : '');
        box.draggable = false;
        box.style.top = (sm / 1440 * 960) + 'px';
        box.style.height = height + 'px';
        if (timed.length > 1) { box.style.left = (6 + (idx % 3) * 12) + 'px'; box.style.right = (6 + ((timed.length - idx - 1) % 3) * 12) + 'px'; }
        box.innerHTML = '<span class="cmpcal-resize-handle top" title="Drag to change start time"></span>'
          + '<span class="cmpcal-resize-handle bottom" title="Drag to change end time"></span>'
          + '<span class="cmpcal-time-event-title">' + iconForEvent(ev) + escapeHtml(ev.title) + '</span>'
          + '<span class="time">' + fmtTime(s) + ' - ' + fmtTime(e) + '</span>';
        box.addEventListener('click', function(x){
          x.stopPropagation();
          if (state.suppressEventClick) {
            state.suppressEventClick = false;
            x.preventDefault();
            return;
          }
          openEventModal(ev);
        });
        box.dataset.eventId = ev.id;
        box.addEventListener('pointerdown', function(x){ startEventMove(x, ev, col, box, sm, em); });
        box.querySelectorAll('.cmpcal-resize-handle').forEach(function(handle){
          handle.addEventListener('click', function(x){
            x.preventDefault();
            x.stopPropagation();
          });
          handle.addEventListener('pointerdown', function(x){ startResize(x, ev, day, col, box, handle.classList.contains('top') ? 'start' : 'end', sm, em); });
        });
        box.addEventListener('mouseenter', function(x){ showTooltip(ev,x); });
        box.addEventListener('mousemove', moveTooltip);
        box.addEventListener('mouseleave', hideTooltip);
        col.appendChild(box);
      });
      if (sameDay(day,new Date())) {
        var now = new Date();
        var line = document.createElement('div');
        line.className = 'cmpcal-now-line';
        line.style.top = (((now.getHours()*60 + now.getMinutes()) / 1440) * 960) + 'px';
        col.appendChild(line);
      }
      timeline.appendChild(col);
    });
  }
  function startResize(e, ev, day, col, box, edge, startMinutes, endMinutes){
    e.preventDefault();
    e.stopPropagation();
    hideTooltip();
    state.suppressEventClick = true;
    state.suppressScheduleClick = true;
    if (e.currentTarget && typeof e.currentTarget.setPointerCapture === 'function') {
      try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {}
    }
    box.draggable = false;
    box.classList.add('is-resizing');
    var nextStart = startMinutes;
    var nextEnd = endMinutes;
    function paint(){
      box.style.top = (nextStart / 1440 * 960) + 'px';
      box.style.height = (Math.max(24, (nextEnd - nextStart) / 1440 * 960)) + 'px';
      var compact = Math.max(24, (nextEnd - nextStart) / 1440 * 960) < 36;
      box.classList.toggle('is-compact', compact);
      var time = box.querySelector('.time');
      if (time) { time.textContent = fmtTime(dateWithMinutes(day, nextStart)) + ' - ' + fmtTime(dateWithMinutes(day, nextEnd)); }
    }
    function move(x){
      var minutes = minutesFromPointer(x, col);
      if (edge === 'start') {
        nextStart = Math.min(minutes, endMinutes - 15);
      } else {
        nextEnd = Math.max(minutes, startMinutes + 15);
      }
      paint();
    }
    function up(){
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      box.classList.remove('is-resizing');
      box.draggable = true;
      if (nextStart !== startMinutes || nextEnd !== endMinutes) {
        confirmResizeChange(ev, dateWithMinutes(day, nextStart), dateWithMinutes(day, nextEnd), edge);
      } else {
        setTimeout(function(){ state.suppressEventClick = false; }, 0);
      }
      setTimeout(function(){ state.suppressScheduleClick = false; }, 300);
    }
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
  }
  function updateDragPreview(e, col, day){
    if (!state.draggingEventId) { return; }
    var ev = events.find(function(x){ return String(x.id) === String(state.draggingEventId); });
    if (!ev || ev.is_all_day) { return; }
    moveDragGhost(e);
    var minutes = minutesFromPointer(e, col);
    var start = dateWithMinutes(day, minutes);
    var end = addMinutes(start, durationMinutes(ev));
    updateDragGhostTime(start, end);
  }
  function startEventMove(e, ev, sourceCol, box, startMinutes, endMinutes){
    if (e.button != null && e.button !== 0) { return; }
    if (e.target.closest('.cmpcal-resize-handle')) { return; }
    var startX = e.clientX;
    var startY = e.clientY;
    var moved = false;
    var lastCol = sourceCol;
    var lastMinutes = startMinutes;
    var duration = Math.max(15, endMinutes - startMinutes);
    var originalRect = box.getBoundingClientRect();
    var pointerOffsetX = startX - originalRect.left;
    var pointerOffsetY = startY - originalRect.top;
    var original = {
      position: box.style.position,
      left: box.style.left,
      top: box.style.top,
      right: box.style.right,
      width: box.style.width,
      height: box.style.height
    };
    if (typeof box.setPointerCapture === 'function') {
      try { box.setPointerCapture(e.pointerId); } catch (err) {}
    }
    function clearTargets(){
      document.querySelectorAll('.cmpcal-time-col.is-drop-target').forEach(function(node){
        node.classList.remove('is-drop-target');
      });
    }
    function paint(targetCol, pointerEvent){
      var colRect = targetCol.getBoundingClientRect();
      var left = clamp(pointerEvent.clientX - pointerOffsetX, colRect.left + 6, colRect.right - originalRect.width - 6);
      var topPx = pointerEvent.clientY - pointerOffsetY - colRect.top;
      var minutes = snapMinutes((topPx / colRect.height) * 1440);
      box.style.left = left + 'px';
      box.style.top = (colRect.top + (minutes / 1440 * colRect.height)) + 'px';
      box.style.right = 'auto';
      box.style.width = originalRect.width + 'px';
      box.style.height = originalRect.height + 'px';
      var day = parseYmd(targetCol.dataset.day || ymd(state.current));
      var start = dateWithMinutes(day, minutes);
      var end = addMinutes(start, duration);
      var time = box.querySelector('.time');
      if (time) { time.textContent = fmtTime(start) + ' - ' + fmtTime(end); }
      targetCol.classList.add('is-drop-target');
      lastCol = targetCol;
      lastMinutes = minutes;
    }
    function move(x){
      if (!moved && Math.abs(x.clientX - startX) + Math.abs(x.clientY - startY) < 5) { return; }
      x.preventDefault();
      if (!moved) {
        moved = true;
        hideTooltip();
        state.suppressEventClick = true;
        state.suppressScheduleClick = true;
        box.classList.add('is-moving');
        box.style.position = 'fixed';
        box.style.left = originalRect.left + 'px';
        box.style.top = originalRect.top + 'px';
        box.style.right = 'auto';
        box.style.width = originalRect.width + 'px';
        box.style.height = originalRect.height + 'px';
      }
      clearTargets();
      var targetCol = timeColumnFromPoint(x.clientX, x.clientY);
      if (targetCol && targetCol.dataset.day) {
        paint(targetCol, x);
      } else {
        box.style.left = (x.clientX - pointerOffsetX) + 'px';
        box.style.top = (x.clientY - pointerOffsetY) + 'px';
      }
    }
    function up(x){
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      clearTargets();
      if (typeof box.releasePointerCapture === 'function') {
        try { box.releasePointerCapture(e.pointerId); } catch (err) {}
      }
      if (!moved) { return; }
      x.preventDefault();
      box.classList.remove('is-moving');
      if (lastCol && lastCol.dataset.day) {
        var day = parseYmd(lastCol.dataset.day);
        var proposedStart = dateWithMinutes(day, lastMinutes);
        var proposedEnd = addMinutes(proposedStart, duration);
        state.pendingVisualChange = true;
        openChangeConfirm(ev, proposedStart, proposedEnd, proposedEventWindowLabel(proposedStart, proposedEnd, false));
      } else {
        box.style.position = original.position;
        box.style.left = original.left;
        box.style.top = original.top;
        box.style.right = original.right;
        box.style.width = original.width;
        box.style.height = original.height;
        renderAll();
      }
      setTimeout(function(){
        state.suppressEventClick = false;
        state.suppressScheduleClick = false;
      }, 300);
    }
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
  }
  function confirmChange(eventId, proposedDay){
    var ev = events.find(function(x){ return String(x.id) === String(eventId); });
    if (!ev) { return; }
    var proposedStart = movedDateForEvent(ev, proposedDay, false);
    var proposedEnd = movedDateForEvent(ev, proposedDay, true);
    openChangeConfirm(ev, proposedStart, proposedEnd, ev.is_all_day ? fmtRange(proposedDay) : fmtDateTime(proposedStart));
  }
  function confirmChangeAtMinutes(eventId, proposedDay, minutes){
    var ev = events.find(function(x){ return String(x.id) === String(eventId); });
    if (!ev) { return; }
    if (ev.is_all_day) {
      confirmChange(eventId, proposedDay);
      return;
    }
    var proposedStart = dateWithMinutes(proposedDay, minutes);
    var proposedEnd = addMinutes(proposedStart, durationMinutes(ev));
    openChangeConfirm(ev, proposedStart, proposedEnd, fmtDateTime(proposedStart) + ' - ' + fmtTime(proposedEnd));
  }
  function openChangeConfirm(ev, proposedStart, proposedEnd, proposedLabel){
    setChangeForm(ev, proposedStart, proposedEnd);
    document.getElementById('cmpcalConfirmDetails').innerHTML = detailRows({
      'Event': ev.title,
      'Current start': ev.is_all_day ? fmtRange(parseDt(ev.starts_at)) : fmtDateTime(parseDt(ev.starts_at)),
      'Current end': ev.is_all_day ? fmtRange(parseDt(ev.ends_at || ev.starts_at)) : fmtDateTime(parseDt(ev.ends_at || ev.starts_at)),
      'Proposed start': ev.is_all_day ? fmtRange(proposedStart) : fmtDateTime(proposedStart),
      'Proposed end': ev.is_all_day ? fmtRange(proposedEnd) : fmtDateTime(proposedEnd),
      'Governance state': ev.governance_state || 'Not set',
      'Linked object': (ev.linked_object_type || 'Not linked') + ' #' + (ev.linked_object_id || ev.source_id || ''),
    });
    document.getElementById('cmpcalDeadlineMoveWarning').hidden = !(ev.is_locked || ev.requires_approval_to_move || String(ev.event_type || '').indexOf('deadline') !== -1);
    showDialog('calendarConfirmChangeModal');
  }
  function confirmResizeChange(ev, proposedStart, proposedEnd, edge){
    state.pendingVisualChange = true;
    setChangeForm(ev, proposedStart, proposedEnd);
    document.getElementById('cmpcalConfirmDetails').innerHTML = detailRows({
      'Event': ev.title,
      'Current start': fmtDateTime(parseDt(ev.starts_at)),
      'Current end': fmtDateTime(parseDt(ev.ends_at || ev.starts_at)),
      'Proposed start': fmtDateTime(proposedStart),
      'Proposed end': fmtDateTime(proposedEnd),
      'Change': edge === 'start' ? 'Start time adjusted; end time kept' : 'End time adjusted; start time kept',
      'Governance state': ev.governance_state || 'Not set',
      'Linked object': (ev.linked_object_type || 'Not linked') + ' #' + (ev.linked_object_id || ev.source_id || ''),
    });
    document.getElementById('cmpcalDeadlineMoveWarning').hidden = !(ev.is_locked || ev.requires_approval_to_move || String(ev.event_type || '').indexOf('deadline') !== -1);
    showDialog('calendarConfirmChangeModal');
  }
  function setChangeForm(ev, proposedStart, proposedEnd){
    document.getElementById('cmpcalChangeEventId').value = ev.id;
    document.getElementById('cmpcalChangeStartsAt').value = postDateTime(proposedStart);
    document.getElementById('cmpcalChangeEndsAt').value = postDateTime(proposedEnd);
    document.getElementById('cmpcalChangeReason').value = '';
    var mode = 'Governed items create a pending approval request.';
    if (ev.can_edit_directly && String(ev.id).indexOf('manual:') === 0) {
      mode = 'This unlocked manual event will be updated directly.';
    } else if (ev.can_edit_directly && ev.source_type === 'meeting') {
      mode = 'This unlocked meeting will update the meeting schedule directly.';
    }
    document.getElementById('cmpcalChangeModeNote').textContent = mode;
  }
  function renderRange(){
    var el = document.getElementById('cmpcalRange');
    if (state.view === 'month') { el.textContent = monthName(state.current); return; }
    if (state.view === 'week') {
      var s = mondayStart(state.current), e = addDays(s,6);
      el.textContent = fmtRange(s) + ' - ' + fmtRange(e);
      return;
    }
    el.textContent = fmtRange(state.current);
  }
  function renderTimezoneSelect(){
    var sel = document.getElementById('cmpcalTimezoneSelect');
    sel.innerHTML = timezoneOptionsHtml();
    sel.value = state.timezone;
    document.getElementById('cmpcalNewTimezone').innerHTML = timezoneOptionsHtml();
    document.getElementById('cmpcalEditTimezone').innerHTML = timezoneOptionsHtml();
  }
  function timezoneOptionsHtml(){
    return tzChoices.filter(function(t){ return state.favorites.indexOf(t.key) !== -1; }).map(function(t){
      return '<option value="' + escapeHtml(t.zone) + '">' + escapeHtml(t.label + ' (' + t.zone + ')') + '</option>';
    }).join('');
  }
  function renderSettings(){
    var root = document.getElementById('cmpcalFavoriteTimezoneList');
    root.innerHTML = tzChoices.map(function(t){
      var checked = state.favorites.indexOf(t.key) !== -1 ? ' checked' : '';
      return '<label><input type="checkbox" value="' + escapeHtml(t.key) + '"' + checked + '> ' + escapeHtml(t.label + ' (' + t.zone + ')') + '</label>';
    }).join('');
    document.querySelectorAll('#cmpcalTimeFormatList input').forEach(function(input){
      input.checked = input.value === state.timeFormat;
    });
  }
  function updateReturnFields(){
    document.querySelectorAll('[data-cmpcal-return-date]').forEach(function(input){ input.value = ymd(state.current); });
    document.querySelectorAll('[data-cmpcal-return-view]').forEach(function(input){ input.value = state.view; });
    document.querySelectorAll('[data-cmpcal-return-scroll]').forEach(function(input){ input.value = String(Math.max(0, Math.round(window.scrollY || 0))); });
  }
  function renderAll(){
    document.getElementById('cmpcalViewSelect').value = state.view;
    document.querySelectorAll('.cmpcal-view').forEach(function(el){ el.classList.remove('is-active'); });
    document.getElementById('cmpcal' + state.view.charAt(0).toUpperCase() + state.view.slice(1) + 'View').classList.add('is-active');
    renderMini();
    renderRange();
    renderTimezoneSelect();
    if (state.view === 'month') { renderMonth(); }
    if (state.view === 'week') { renderAgenda('week'); }
    if (state.view === 'day') { renderAgenda('day'); }
    renderSettings();
    updateReturnFields();
    if (!restoredInitialScroll && initialScroll > 0) {
      restoredInitialScroll = true;
      setTimeout(function(){ window.scrollTo(0, initialScroll); }, 0);
    }
  }

  document.getElementById('cmpcalMiniPrev').addEventListener('click', function(){ state.mini = addMonths(state.mini,-1); renderMini(); });
  document.getElementById('cmpcalMiniNext').addEventListener('click', function(){ state.mini = addMonths(state.mini,1); renderMini(); });
  document.getElementById('cmpcalPrev').addEventListener('click', function(){ state.current = state.view === 'month' ? addMonths(state.current,-1) : addDays(state.current, state.view === 'week' ? -7 : -1); state.selected = state.current; state.mini = startOfMonth(state.current); renderAll(); });
  document.getElementById('cmpcalNext').addEventListener('click', function(){ state.current = state.view === 'month' ? addMonths(state.current,1) : addDays(state.current, state.view === 'week' ? 7 : 1); state.selected = state.current; state.mini = startOfMonth(state.current); renderAll(); });
  document.getElementById('cmpcalToday').addEventListener('click', function(){ state.current = startOfDay(new Date()); state.selected = state.current; state.mini = startOfMonth(state.current); renderAll(); });
  document.getElementById('cmpcalViewSelect').addEventListener('change', function(e){ state.view = e.target.value; localStorage.setItem('ipcaComplianceCalendarView', state.view); renderAll(); });
  document.getElementById('cmpcalTimezoneSelect').addEventListener('change', function(e){ state.timezone = e.target.value; localStorage.setItem('ipcaComplianceCalendarTimezone', state.timezone); renderAll(); });
  document.getElementById('cmpcalNewAllDay').addEventListener('change', updateAllDayFields);
  document.getElementById('cmpcalEditAllDay').addEventListener('change', updateEditAllDayFields);
  document.getElementById('cmpcalSaveTimezoneSettings').addEventListener('click', function(){
    var selected = Array.from(document.querySelectorAll('#cmpcalFavoriteTimezoneList input:checked')).map(function(i){ return i.value; });
    if (selected.length === 0) { selected = ['browser']; }
    state.favorites = selected;
    var selectedTimeFormat = document.querySelector('#cmpcalTimeFormatList input:checked');
    state.timeFormat = selectedTimeFormat ? selectedTimeFormat.value : '24';
    if (!tzChoices.some(function(t){ return t.zone === state.timezone && selected.indexOf(t.key) !== -1; })) {
      state.timezone = tzChoices.find(function(t){ return t.key === selected[0]; }).zone;
    }
    localStorage.setItem('ipcaComplianceCalendarFavoriteTimezones', JSON.stringify(state.favorites));
    localStorage.setItem('ipcaComplianceCalendarTimezone', state.timezone);
    localStorage.setItem('ipcaComplianceCalendarTimeFormat', state.timeFormat);
    renderAll();
  });
  document.querySelectorAll('[data-cmpcal-filter]').forEach(function (box) {
    box.addEventListener('change', function(){
      if (box.checked) { state.activeTypes.add(box.getAttribute('data-cmpcal-filter')); }
      else { state.activeTypes.delete(box.getAttribute('data-cmpcal-filter')); }
      renderAll();
    });
  });
  document.getElementById('cmpcalEditEvent').addEventListener('click', function(){ openEditManualModal(state.selectedEvent); });
  document.getElementById('cmpcalLinkEvent').addEventListener('click', function(){ openLinkManualModal(state.selectedEvent); });
  normalizeLinkModalLabels();
  populateLinkedDetails('cmpcalNewLinkedId', document.getElementById('cmpcalNewLinkedType').value, document.getElementById('cmpcalNewLinkedId').value, '');
  populateLinkedDetails('cmpcalEditLinkedId', document.getElementById('cmpcalEditLinkedType').value, document.getElementById('cmpcalEditLinkedId').value, '');
  populateLinkDetails(document.getElementById('cmpcalLinkType').value, document.getElementById('cmpcalLinkId').value, '');
  document.getElementById('cmpcalNewLinkedType').addEventListener('change', function(e){
    populateLinkedDetails('cmpcalNewLinkedId', e.target.value, '', '');
  });
  document.getElementById('cmpcalEditLinkedType').addEventListener('change', function(e){
    populateLinkedDetails('cmpcalEditLinkedId', e.target.value, '', '');
  });
  document.getElementById('cmpcalLinkType').addEventListener('change', function(e){
    populateLinkDetails(e.target.value, '', '');
  });
  document.getElementById('cmpcalNewEventForm').addEventListener('submit', function(e){
    if (!validateLinkedDetails('cmpcalNewLinkedType', 'cmpcalNewLinkedId')) {
      e.preventDefault();
      return;
    }
    if (document.getElementById('cmpcalNewAllDay').value !== '1') {
      var start = normalizeTimeText(document.getElementById('cmpcalNewStart').value);
      var end = normalizeTimeText(document.getElementById('cmpcalNewEnd').value);
      if (!start || !end) {
        e.preventDefault();
        alert(state.timeFormat === '12' ? 'Enter times like 9:00 AM and 10:00 AM.' : 'Enter times like 09:00 and 10:00.');
        return;
      }
      document.getElementById('cmpcalNewStart').value = start;
      document.getElementById('cmpcalNewEnd').value = end;
    }
    updateReturnFields();
  });
  document.getElementById('cmpcalEditEventForm').addEventListener('submit', function(e){
    if (!validateLinkedDetails('cmpcalEditLinkedType', 'cmpcalEditLinkedId')) {
      e.preventDefault();
      return;
    }
    if (document.getElementById('cmpcalEditAllDay').value !== '1') {
      var start = normalizeTimeText(document.getElementById('cmpcalEditStart').value);
      var end = normalizeTimeText(document.getElementById('cmpcalEditEnd').value);
      if (!start || !end) {
        e.preventDefault();
        alert(state.timeFormat === '12' ? 'Enter times like 9:00 AM and 10:00 AM.' : 'Enter times like 09:00 and 10:00.');
        return;
      }
      document.getElementById('cmpcalEditStart').value = start;
      document.getElementById('cmpcalEditEnd').value = end;
    }
    updateReturnFields();
  });
  document.getElementById('cmpcalLinkEventForm').addEventListener('submit', function(e){
    if (!validateLinkedDetails('cmpcalLinkType', 'cmpcalLinkId')) {
      e.preventDefault();
      return;
    }
    updateReturnFields();
  });
  document.getElementById('cmpcalConfirmChangeForm').addEventListener('submit', function(){
    state.pendingVisualChange = false;
    updateReturnFields();
  });
  document.getElementById('calendarConfirmChangeModal').addEventListener('close', function(){
    if (state.pendingVisualChange) {
      state.pendingVisualChange = false;
      state.suppressEventClick = false;
      renderAll();
    }
  });
  document.getElementById('calendarConfirmChangeModal').addEventListener('cancel', function(){
    if (state.pendingVisualChange) {
      state.pendingVisualChange = false;
      state.suppressEventClick = false;
      setTimeout(renderAll, 0);
    }
  });
  document.querySelectorAll('.cmpcal-queue-actions').forEach(function(form){
    form.addEventListener('submit', updateReturnFields);
  });
  document.getElementById('cmpcalDeleteEventForm').addEventListener('submit', function(e){
    updateReturnFields();
    if (!document.getElementById('cmpcalDeleteEventId').value) {
      e.preventDefault();
      alert('Only unlocked manual calendar events can be deleted from the schedule.');
      return;
    }
    if (!confirm('Are yous sure you want to delete this event?')) {
      e.preventDefault();
    }
  });

  renderAll();
})();
</script>
<?php
compliance_page_close();
cw_footer();
