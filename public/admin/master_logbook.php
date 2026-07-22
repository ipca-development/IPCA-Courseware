<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();
cw_header('Master Logbook');
?>
<style>
.ml-page{display:grid;gap:14px}.ml-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:16px;padding:16px;box-shadow:0 10px 22px rgba(15,23,42,.05)}.ml-hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.ml-title{margin:0;font-size:26px;color:#0f172a}.ml-muted{color:#64748b;font-size:12px}.ml-tabs{display:flex;gap:8px;flex-wrap:wrap}.ml-tab{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:800;cursor:pointer}.ml-tab.is-active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.ml-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}.ml-select{border:1px solid #cbd5e1;border-radius:10px;padding:6px 8px;background:#fff}.ml-table-wrap{overflow-x:auto}.ml-table{width:100%;border-collapse:collapse;min-width:1340px;font-size:13px}.ml-table th,.ml-table td{border-bottom:1px solid #e2e8f0;padding:7px 8px;text-align:left;vertical-align:middle}.ml-table th{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#475569;background:#f8fafc}.ml-table thead tr:first-child th{text-align:center;border-bottom:0;padding-bottom:3px}.ml-table thead tr:last-child th{padding-top:3px}.ml-table th:nth-child(5),.ml-table td:nth-child(5),.ml-table th:nth-child(8),.ml-table td:nth-child(8){border-left:2px solid #e2e8f0}.ml-sort{border:0;background:transparent;color:inherit;font:inherit;font-weight:800;cursor:pointer;padding:0}.ml-row{cursor:pointer;transition:background-color .12s ease,box-shadow .12s ease}.ml-row:hover,.ml-row.is-expanded{background:#f8fafc;box-shadow:inset 3px 0 0 #2563eb}.ml-chip-stack{display:grid;gap:4px}.ml-chip{display:inline-flex;align-items:center;gap:5px;width:max-content;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:900;letter-spacing:.01em;background:#e2e8f0;color:#334155}.ml-chip svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.ml-activity-flight{background:#dbeafe;color:#1e40af}.ml-activity-sim{background:#ede9fe;color:#5b21b6}.ml-activity-ground{background:#f0fdf4;color:#166534}.ml-activity-evidence{background:#f8fafc;color:#475569}.ml-status-confirmed{background:#dcfce7;color:#166534}.ml-status-awaiting{background:#fef3c7;color:#92400e}.ml-status-review{background:#ffedd5;color:#9a3412}.ml-status-verified{background:#dbeafe;color:#1e40af}.ml-status-finalized{background:#e5e7eb;color:#111827}.ml-aircraft-pill{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:900;border:1px solid currentColor}.ml-crew{display:grid;gap:5px;font-size:11px;line-height:1.13;min-width:170px}.ml-crew-member{display:grid;gap:1px}.ml-crew-name{font-weight:750;color:#0f172a;white-space:nowrap}.ml-crew-role{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.ml-date{font-size:12px;font-weight:700;color:#334155;white-space:nowrap}.ml-ad{font-weight:900;color:#0f172a}.ml-time,.ml-meter,.ml-duration,.ml-landings{font-size:12px;color:#334155;white-space:nowrap}.ml-duration,.ml-landings{font-weight:800}.ml-evidence-row{display:flex;gap:6px;align-items:center;flex-wrap:nowrap}.ml-evidence{display:inline-flex;align-items:center;justify-content:center;min-width:38px;border-radius:999px;padding:3px 6px;font-size:10px;font-weight:900;background:#e5e7eb;color:#6b7280}.ml-evidence-on{background:#dcfce7;color:#166534}.ml-pill-row{display:flex;gap:5px;flex-wrap:wrap}.ml-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 7px;font-size:11px;font-weight:800;background:#e2e8f0;color:#334155}.ml-pill-usable{background:#dcfce7;color:#166534}.ml-pill-present{background:#dbeafe;color:#1e40af}.ml-pill-processing{background:#fef3c7;color:#92400e}.ml-pill-failed,.ml-pill-stale,.ml-pill-superseded{background:#fee2e2;color:#991b1b}.ml-pill-incomplete,.ml-pill-unresolved{background:#ffedd5;color:#9a3412}.ml-action{border:0;border-radius:9px;background:#1d4ed8;color:#fff;font-weight:800;padding:7px 10px;cursor:pointer;text-decoration:none;display:inline-flex}.ml-action.secondary{background:#475569}.ml-action:disabled{opacity:.6;cursor:not-allowed}.ml-pagination{display:flex;align-items:center;gap:10px;justify-content:flex-end;flex-wrap:wrap}.ml-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;color:#475569}.ml-status{min-height:18px}.ml-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px}.ml-loading{opacity:.65}.ml-expanded td{background:#f8fafc;padding:0 8px 12px}.ml-expanded-panel{border:1px solid #dbe3ef;border-radius:14px;background:#fff;margin:0 0 4px;padding:14px;display:grid;gap:12px;box-shadow:0 8px 18px rgba(15,23,42,.05)}.ml-expanded-title{display:flex;justify-content:space-between;gap:12px;align-items:center}.ml-leg-list{display:grid;gap:10px}.ml-leg-card{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.ml-leg-title{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#475569;font-weight:900;margin-bottom:7px}.ml-leg-flow{display:grid;grid-template-columns:1fr auto 1fr;gap:10px;align-items:center}.ml-leg-arrow{color:#64748b;font-weight:900}.ml-leg-point{display:grid;gap:2px}.ml-leg-ad{font-weight:900;color:#0f172a}.ml-leg-meta{font-size:12px;color:#475569}.ml-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.58);display:none;z-index:9999;padding:28px;overflow:auto}.ml-modal-backdrop.is-open{display:block}.ml-modal{max-width:1120px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 25px 70px rgba(15,23,42,.35);overflow:hidden}.ml-modal-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #e2e8f0}.ml-modal-body{padding:18px 20px;display:grid;gap:14px}.ml-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.ml-kv,.ml-section{border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:12px}.ml-section{background:#fff;display:grid;gap:10px}.ml-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;font-weight:800}.ml-value{font-weight:850;color:#0f172a;margin-top:3px}.ml-list{margin:0;padding-left:18px}.ml-pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:220px;overflow:auto;font-size:12px}.ml-empty{padding:22px;color:#64748b;text-align:center}.ml-source-note{font-size:12px;color:#64748b;margin-top:4px}
.ml-table{min-width:1120px;table-layout:fixed}.ml-col-activity{width:74px}.ml-col-date{width:112px}.ml-col-aircraft{width:86px}.ml-col-crew{width:190px}.ml-col-dep,.ml-col-arr{width:68px}.ml-col-flight{width:66px}.ml-col-ldg{width:52px}.ml-col-evidence{width:150px}.ml-table th,.ml-table td{padding-left:6px;padding-right:6px;overflow:hidden}.ml-group-start{border-left:2px solid #e2e8f0!important}.ml-blank{color:#cbd5e1;font-weight:700}.ml-crew{min-width:0}.ml-evidence-row{gap:4px}.ml-evidence{min-width:32px;padding:2px 5px;font-size:9px}.ml-chip{padding:2px 6px;font-size:9px}.ml-chip svg{width:11px;height:11px}
.ml-table thead tr:first-child th:nth-child(5){border-left:0}
</style>

<div class="ml-page" data-master-logbook>
  <section class="ml-card ml-hero">
    <div>
      <h1 class="ml-title">Master Logbook</h1>
      <p class="ml-muted">Read-only Training Event view for today&apos;s training activity ledger. Flight evidence, audio, replay, ADS-B, transcript, and proposal status stay attached to the event without changing source systems.</p>
    </div>
    <div class="ml-tabs" role="tablist" aria-label="Master Logbook views">
      <button class="ml-tab is-active" type="button" data-view="normal">Training Events</button>
      <button class="ml-tab" type="button" data-view="unresolved">Unresolved Records</button>
    </div>
  </section>

  <section class="ml-card">
    <div class="ml-toolbar">
      <div>
        <strong data-view-title>Training Events</strong>
        <div class="ml-muted" data-view-description>Resolved operational Training Events only. Orphan evidence stays out of this view.</div>
      </div>
      <label class="ml-muted">Rows per page
        <select class="ml-select" data-page-size>
          <option value="10">10</option>
          <option value="25" selected>25</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
      </label>
    </div>
  </section>

  <section class="ml-card">
    <div class="ml-status ml-muted" data-status>Loading Master Logbook...</div>
    <div class="ml-table-wrap">
      <table class="ml-table">
        <colgroup>
          <col class="ml-col-activity">
          <col class="ml-col-date">
          <col class="ml-col-aircraft">
          <col class="ml-col-crew">
          <col class="ml-col-dep">
          <col class="ml-col-dep">
          <col class="ml-col-dep">
          <col class="ml-col-arr">
          <col class="ml-col-arr">
          <col class="ml-col-arr">
          <col class="ml-col-flight">
          <col class="ml-col-ldg">
          <col class="ml-col-evidence">
        </colgroup>
        <thead>
          <tr>
            <th rowspan="2">Activity</th>
            <th rowspan="2"><button class="ml-sort" type="button" data-sort="date">Date</button></th>
            <th rowspan="2"><button class="ml-sort" type="button" data-sort="aircraft">Aircraft</button></th>
            <th rowspan="2">Crew</th>
            <th class="ml-group-start" colspan="3">Departure</th>
            <th class="ml-group-start" colspan="3">Arrival</th>
            <th rowspan="2">Flight</th>
            <th rowspan="2">LDG</th>
            <th rowspan="2">Evidence</th>
          </tr>
          <tr>
            <th class="ml-group-start">DEP</th>
            <th>Time</th>
            <th>Hobbs Out</th>
            <th class="ml-group-start">ARR</th>
            <th>Time</th>
            <th>Hobbs In</th>
          </tr>
        </thead>
        <tbody data-rows>
          <tr><td colspan="13" class="ml-empty">Loading...</td></tr>
        </tbody>
      </table>
    </div>
    <div class="ml-pagination" style="margin-top:14px">
      <button class="ml-action secondary" type="button" data-prev>Previous</button>
      <span class="ml-muted" data-page-label>Page 1</span>
      <button class="ml-action secondary" type="button" data-next>Next</button>
    </div>
  </section>
</div>

<div class="ml-modal-backdrop" data-detail-modal aria-hidden="true">
  <div class="ml-modal" role="dialog" aria-modal="true" aria-labelledby="ml-detail-title">
    <div class="ml-modal-header">
      <div>
        <div class="ml-label">Training Event Detail</div>
        <h2 id="ml-detail-title" style="margin:2px 0">Loading...</h2>
        <div class="ml-muted" data-detail-subtitle></div>
      </div>
      <button class="ml-action secondary" type="button" data-close-detail>Close</button>
    </div>
    <div class="ml-modal-body" data-detail-body>
      <div class="ml-muted">Loading detail...</div>
    </div>
  </div>
</div>

<script>
(function () {
  const root = document.querySelector('[data-master-logbook]');
  if (!root) return;

  const state = { view: 'normal', page: 1, pageSize: 25, sortField: 'date', sortDirection: 'desc', totalPages: 1 };
  const rowsEl = root.querySelector('[data-rows]');
  const statusEl = root.querySelector('[data-status]');
  const pageLabel = root.querySelector('[data-page-label]');
  const detailModal = document.querySelector('[data-detail-modal]');
  const detailBody = document.querySelector('[data-detail-body]');
  const detailTitle = document.getElementById('ml-detail-title');
  const detailSubtitle = document.querySelector('[data-detail-subtitle]');
  const detailCache = new Map();

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  }

  function displayValue(value) {
    const text = String(value == null ? '' : value).trim();
    return text === '' || text === '--' || text === '-' ? '<span class="ml-blank">-</span>' : esc(text);
  }

  function resolved(value) {
    if (!value || typeof value !== 'object') return value == null || value === '' ? '--' : String(value);
    const resolvedValue = value.resolved_value;
    const rawValue = value.raw_source_value;
    if (resolvedValue != null && String(resolvedValue).trim() !== '') return String(resolvedValue);
    if (rawValue != null && String(rawValue).trim() !== '') return String(rawValue);
    return '--';
  }

  function compactDate(value) {
    const text = String(value || '').trim();
    if (!text) return '--';
    const iso = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
    let date;
    if (iso) {
      date = new Date(Number(iso[1]), Number(iso[2]) - 1, Number(iso[3]));
    } else {
      date = new Date(text);
    }
    if (!Number.isFinite(date.getTime())) return text.length > 16 ? text.slice(0, 16) : text;
    return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  function compactTime(value) {
    const text = String(value || '').trim();
    if (!text || text === '--') return '--';
    const match = text.match(/(\d{1,2}):(\d{2})/);
    return match ? match[1].padStart(2, '0') + ':' + match[2] : text;
  }

  function adSummary(value, side) {
    const text = resolved(value);
    if (!text || text === '--') return '--';
    const parts = text.split(/\s*[-–]\s*/).map((part) => part.trim()).filter(Boolean);
    if (parts.length > 1) {
      return side === 'arr' ? parts[parts.length - 1] : parts[0];
    }
    return text;
  }

  function numericTime(row, field) {
    const value = resolved(row[field]);
    const parsed = Date.parse(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function formatHours(value) {
    const text = String(value == null ? '' : value).trim();
    if (!text || text === '--') return '--';
    const match = text.match(/-?\d+(?:\.\d+)?/);
    if (!match) return text;
    return Number(match[0]).toFixed(1) + ' h';
  }

  function formatLandings(value) {
    const text = resolved(value);
    if (!text || text === '--') return '--';
    const match = text.match(/\d+/);
    return match ? match[0] + ' LDG' : text;
  }

  function groupEventRows(rows) {
    const groups = [];
    const byKey = new Map();
    rows.forEach((row) => {
      const key = String(row.event_key || '');
      if (!byKey.has(key)) {
        const group = { key, rows: [], summary: row };
        byKey.set(key, group);
        groups.push(group);
      }
      byKey.get(key).rows.push(row);
    });
    groups.forEach((group) => {
      group.rows.sort((a, b) => numericTime(a, 'departure_local_time') - numericTime(b, 'departure_local_time'));
      const first = group.rows[0] || group.summary;
      const last = group.rows[group.rows.length - 1] || first;
      group.summary = Object.assign({}, first, {
        arrival_airport: last.arrival_airport,
        arrival_local_time: last.arrival_local_time,
        arrival_hobbs: last.arrival_hobbs,
        landings: group.rows.reduce((total, row) => {
          const match = resolved(row.landings).match(/\d+/);
          return total + (match ? Number(match[0]) : 0);
        }, 0) || first.landings,
        hobbs_duration: group.rows.reduce((total, row) => {
          const match = String(resolved(row.hobbs_duration)).match(/-?\d+(?:\.\d+)?/);
          return total + (match ? Number(match[0]) : 0);
        }, 0) || first.hobbs_duration
      });
    });
    return groups;
  }

  function aircraftStyle(registration) {
    const text = String(registration || 'UNKNOWN');
    let hash = 0;
    for (let i = 0; i < text.length; i += 1) {
      hash = ((hash << 5) - hash + text.charCodeAt(i)) | 0;
    }
    const hue = Math.abs(hash) % 360;
    return 'background:hsl(' + hue + ' 78% 92%);color:hsl(' + hue + ' 72% 24%);border-color:hsl(' + hue + ' 58% 70%)';
  }

  function aircraftPill(value) {
    const registration = resolved(value);
    return '<span class="ml-aircraft-pill" style="' + esc(aircraftStyle(registration)) + '">' + esc(registration) + '</span>';
  }

  function crewBlock(row) {
    const crew = [
      { name: resolved(row.pilot_1), role: resolved(row.pilot_1_role) },
      { name: resolved(row.pilot_2), role: resolved(row.pilot_2_role) }
    ].filter((member) => member.name && member.name !== '--');
    if (!crew.length) return '<span class="ml-muted">--</span>';
    return '<div class="ml-crew">' + crew.map((member) => (
      '<div class="ml-crew-member"><span class="ml-crew-name">' + esc(member.name) + '</span>' + (member.role && member.role !== '--' && member.role !== 'unresolved' && member.role !== 'not_traced' ? '<span class="ml-crew-role">' + esc(member.role) + '</span>' : '') + '</div>'
    )).join('') + '</div>';
  }

  function evidenceStateClass(evidence) {
    const stateText = evidence && evidence.state ? String(evidence.state) : 'not_available';
    if (stateText === 'usable' || stateText === 'present') return 'ml-evidence-on';
    return '';
  }

  function evidenceIndicator(label, evidence) {
    const stateText = evidence && evidence.state ? String(evidence.state) : 'not_available';
    return '<span class="ml-evidence ' + esc(evidenceStateClass(evidence)) + '" title="' + esc(label + ': ' + stateText.replace(/_/g, ' ')) + '">' + esc(label) + '</span>';
  }

  function evidenceIndicators(row) {
    return '<div class="ml-evidence-row">' +
      evidenceIndicator('FDM', row.fdm) +
      evidenceIndicator('CVR', row.cvr) +
      evidenceIndicator('ADSB', row.adsb) +
      evidenceIndicator('Replay', row.replay) +
    '</div>';
  }

  function iconSvg(name) {
    const icons = {
      flight: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 21l2-7-7-4 2-2 8 2 4-7 2 2-4 7 2 8-2 2-4-7-3 6z"/></svg>',
      monitor: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>',
      book: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2"/><path d="M8 7h7M8 11h7"/></svg>',
      file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>',
      check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>',
      clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
      alert: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l10 18H2L12 3z"/><path d="M12 9v4M12 17h.01"/></svg>',
      verified: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.4 2.1 3.2-.2.8 3.1 2.6 1.8-1.3 2.9 1.3 2.9-2.6 1.8-.8 3.1-3.2-.2L12 21l-2.4-2.1-3.2.2-.8-3.1L3 14.2l1.3-2.9L3 8.4l2.6-1.8.8-3.1 3.2.2L12 3z"/><path d="M8.5 12.5l2.2 2.2 4.8-5"/></svg>',
      lock: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>'
    };
    return icons[name] || '';
  }

  function activityDescriptor(row) {
    const source = String(row.source_mode || '');
    if (source === 'simulator') return { label: 'Simulator', icon: 'monitor', className: 'ml-activity-sim' };
    if (source === 'non_flight') return { label: 'Ground Training', icon: 'book', className: 'ml-activity-ground' };
    if (source === 'orphan_recording' || source === 'unresolved_garmin') return { label: 'Flight Evidence', icon: 'file', className: 'ml-activity-evidence' };
    return { label: 'Flight', icon: 'flight', className: 'ml-activity-flight' };
  }

  function reconstructionDescriptor(row) {
    const type = String(row.leg_structure_type || '');
    const conflict = String(row.conflict_status || '');
    const verification = String(row.verification_status || '');
    const finalization = String(row.finalization_status || '');
    if (finalization === 'finalized') return { label: 'Finalized', icon: 'lock', className: 'ml-status-finalized' };
    if (verification === 'verified' || verification === 'system_verified') return { label: 'Verified', icon: 'verified', className: 'ml-status-verified' };
    if (conflict === 'warning' || conflict === 'conflict' || type === 'unresolved_leg_structure' || type === 'inferred_leg') {
      return { label: 'Needs Review', icon: 'alert', className: 'ml-status-review' };
    }
    if (type === 'confirmed_leg') return { label: 'Confirmed', icon: 'check', className: 'ml-status-confirmed' };
    if (type === 'aggregate_dispatch') return { label: 'Awaiting Data', icon: 'clock', className: 'ml-status-awaiting' };
    return { label: 'Needs Review', icon: 'alert', className: 'ml-status-review' };
  }

  function chip(descriptor) {
    return '<span class="ml-chip ' + esc(descriptor.className) + '">' + iconSvg(descriptor.icon) + esc(descriptor.label) + '</span>';
  }

  function activityStatusChips(row) {
    return '<div class="ml-chip-stack">' + chip(activityDescriptor(row)) + chip(reconstructionDescriptor(row)) + '</div>';
  }

  function renderRows(result) {
    const rows = Array.isArray(result.rows) ? result.rows : [];
    const groups = groupEventRows(rows);
    state.totalPages = Number(result.total_pages || 1);
    pageLabel.textContent = 'Page ' + Number(result.page || state.page) + ' of ' + state.totalPages + ' · ' + Number(result.total_matching_rows || 0).toLocaleString() + ' rows';
    root.querySelector('[data-prev]').disabled = state.page <= 1;
    root.querySelector('[data-next]').disabled = state.page >= state.totalPages;
    if (!groups.length) {
      rowsEl.innerHTML = '<tr><td colspan="13" class="ml-empty">No rows in this view.</td></tr>';
      return;
    }
    rowsEl.innerHTML = groups.map((group) => {
      const row = group.summary;
      return '<tr class="ml-row" data-event-key="' + esc(row.event_key) + '">' +
        '<td>' + activityStatusChips(row) + '</td>' +
        '<td><span class="ml-date">' + esc(compactDate(row.date || resolved(row.departure_local_time))) + '</span></td>' +
        '<td>' + aircraftPill(row.aircraft) + '</td>' +
        '<td>' + crewBlock(row) + '</td>' +
        '<td class="ml-group-start"><span class="ml-ad">' + displayValue(adSummary(row.departure_airport, 'dep')) + '</span></td>' +
        '<td><span class="ml-time">' + displayValue(compactTime(resolved(row.departure_local_time))) + '</span></td>' +
        '<td><span class="ml-meter">' + displayValue(resolved(row.departure_hobbs)) + '</span></td>' +
        '<td class="ml-group-start"><span class="ml-ad">' + displayValue(adSummary(row.arrival_airport, 'arr')) + '</span></td>' +
        '<td><span class="ml-time">' + displayValue(compactTime(resolved(row.arrival_local_time))) + '</span></td>' +
        '<td><span class="ml-meter">' + displayValue(resolved(row.arrival_hobbs)) + '</span></td>' +
        '<td><span class="ml-duration">' + displayValue(formatHours(resolved(row.hobbs_duration))) + '</span></td>' +
        '<td><span class="ml-landings">' + displayValue(formatLandings(row.landings)) + '</span></td>' +
        '<td>' + evidenceIndicators(row) + '</td>' +
      '</tr>';
    }).join('');
  }

  async function fetchDetail(eventKey) {
    if (detailCache.has(eventKey)) return detailCache.get(eventKey);
    const response = await fetch('/admin/api/master_logbook_detail.php?event_key=' + encodeURIComponent(eventKey), { credentials: 'same-origin' });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.error || 'Unable to load detail.');
    detailCache.set(eventKey, payload.detail || {});
    return payload.detail || {};
  }

  function legCard(leg, index) {
    const depAd = adSummary(leg.departure_airport, 'dep');
    const arrAd = adSummary(leg.arrival_airport, 'arr');
    const depTime = compactTime(resolved(leg.departure_local_time));
    const arrTime = compactTime(resolved(leg.arrival_local_time));
    const depHobbs = resolved(leg.meter_values && leg.meter_values.hobbs_out ? leg.meter_values.hobbs_out : leg.departure_hobbs);
    const arrHobbs = resolved(leg.meter_values && leg.meter_values.hobbs_in ? leg.meter_values.hobbs_in : leg.arrival_hobbs);
    return '<div class="ml-leg-card">' +
      '<div class="ml-leg-title">Leg ' + esc(index + 1) + '</div>' +
      '<div class="ml-leg-flow">' +
        '<div class="ml-leg-point"><span class="ml-leg-ad">DEP ' + esc(depAd) + '</span><span class="ml-leg-meta">' + esc(depTime) + ' · Hobbs ' + esc(depHobbs) + '</span></div>' +
        '<div class="ml-leg-arrow">→</div>' +
        '<div class="ml-leg-point"><span class="ml-leg-ad">ARR ' + esc(arrAd) + '</span><span class="ml-leg-meta">' + esc(arrTime) + ' · Hobbs ' + esc(arrHobbs) + '</span></div>' +
      '</div>' +
    '</div>';
  }

  function expandedHtml(detail) {
    const legs = Array.isArray(detail.legs) ? detail.legs : [];
    const evidence = detail.evidence || {};
    const confirmedLegs = legs.filter((leg) => String(leg.leg_structure_type || '') === 'confirmed_leg');
    const legHtml = confirmedLegs.length
      ? '<div class="ml-leg-list">' + confirmedLegs.map(legCard).join('') + '</div>'
      : '<div class="ml-muted">No confirmed flight legs are available yet for this Training Event.</div>';
    const replay = evidence.replay && evidence.replay.launch_url
      ? '<a class="ml-action secondary" target="_blank" rel="noopener" href="' + esc(evidence.replay.launch_url) + '">Open Replay</a>'
      : '';
    return '<div class="ml-expanded-panel">' +
      '<div class="ml-expanded-title"><div><strong>Confirmed Flight Legs</strong><div class="ml-muted">Only confirmed legs from the read model are shown here.</div></div><div class="ml-pill-row">' + replay + '<button class="ml-action" type="button" data-open-full-detail>Open Full Details</button></div></div>' +
      legHtml +
      '<div class="ml-grid">' +
        kv('Mission', typeof detail.mission === 'object' ? resolved(detail.mission) : (detail.mission || '--')) +
        kv('FDM', evidence.fdm && evidence.fdm.state ? evidence.fdm.state : 'not_available') +
        kv('CVR', evidence.cvr && evidence.cvr.state ? evidence.cvr.state : 'not_available') +
        kv('Replay', evidence.replay && evidence.replay.state ? evidence.replay.state : 'not_available') +
      '</div>' +
    '</div>';
  }

  async function toggleExpanded(row) {
    const eventKey = row.getAttribute('data-event-key') || '';
    const next = row.nextElementSibling;
    if (next && next.classList.contains('ml-expanded')) {
      next.remove();
      row.classList.remove('is-expanded');
      return;
    }
    rowsEl.querySelectorAll('.ml-expanded').forEach((expanded) => expanded.remove());
    rowsEl.querySelectorAll('.ml-row.is-expanded').forEach((openRow) => openRow.classList.remove('is-expanded'));
    row.classList.add('is-expanded');
    row.insertAdjacentHTML('afterend', '<tr class="ml-expanded"><td colspan="13"><div class="ml-expanded-panel"><span class="ml-muted">Loading Training Event...</span></div></td></tr>');
    const expandedRow = row.nextElementSibling;
    try {
      const detail = await fetchDetail(eventKey);
      expandedRow.querySelector('td').innerHTML = expandedHtml(detail);
      expandedRow.querySelector('[data-open-full-detail]').addEventListener('click', (event) => {
        event.stopPropagation();
        renderDetail(detail);
        detailModal.classList.add('is-open');
        detailModal.setAttribute('aria-hidden', 'false');
      });
    } catch (error) {
      expandedRow.querySelector('td').innerHTML = '<div class="ml-error">' + esc(error.message || error) + '</div>';
    }
  }

  async function loadRows() {
    root.classList.add('ml-loading');
    statusEl.textContent = 'Loading...';
    const params = new URLSearchParams({
      view: state.view,
      page: String(state.page),
      page_size: String(state.pageSize),
      sort_field: state.sortField,
      sort_direction: state.sortDirection,
      include_diagnostics: '1'
    });
    try {
      const response = await fetch('/admin/api/master_logbook_rows.php?' + params.toString(), { credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to load Master Logbook rows.');
      renderRows(payload.result || {});
      const result = payload.result || {};
      statusEl.textContent = 'Showing ' + Number(result.total_matching_rows || 0).toLocaleString() + ' records. Select a row to view flight details.';
    } catch (error) {
      rowsEl.innerHTML = '<tr><td colspan="13" class="ml-empty">Unable to load rows.</td></tr>';
      statusEl.innerHTML = '<div class="ml-error">' + esc(error.message || error) + '</div>';
    } finally {
      root.classList.remove('ml-loading');
    }
  }

  function renderEvidenceMap(evidence) {
    if (!evidence || typeof evidence !== 'object') return '<div class="ml-muted">No evidence reported.</div>';
    return '<div class="ml-grid">' + Object.keys(evidence).map((key) => {
      const item = evidence[key];
      if (!item || typeof item !== 'object') {
        return '<div class="ml-kv"><div class="ml-label">' + esc(key) + '</div><div class="ml-value">--</div></div>';
      }
      const launch = item.launch_url ? '<div style="margin-top:8px"><a class="ml-action secondary" target="_blank" rel="noopener" href="' + esc(item.launch_url) + '">Open Replay</a></div>' : '';
      return '<div class="ml-kv"><div class="ml-label">' + esc(key) + '</div><div class="ml-value">' + esc(item.state || '--') + '</div><div class="ml-muted">' + esc(item.primary_source_key || item.source_status || '--') + '</div>' + launch + '</div>';
    }).join('') + '</div>';
  }

  function kv(label, value) {
    return '<div class="ml-kv"><div class="ml-label">' + esc(label) + '</div><div class="ml-value">' + esc(value == null || value === '' ? '--' : value) + '</div></div>';
  }

  function detailActivityDescriptor(detail) {
    const parsed = detail && detail.identity && detail.identity.parsed ? detail.identity.parsed : {};
    if (parsed.type === 'simulator') return { label: 'Simulator', icon: 'monitor', className: 'ml-activity-sim' };
    if (parsed.type === 'nonflight') return { label: 'Ground Training', icon: 'book', className: 'ml-activity-ground' };
    if (parsed.type === 'orphan_recording' || parsed.type === 'unresolved_garmin_csv') return { label: 'Flight Evidence', icon: 'file', className: 'ml-activity-evidence' };
    return { label: 'Flight', icon: 'flight', className: 'ml-activity-flight' };
  }

  function detailReconstructionDescriptor(detail, legs) {
    const firstLeg = Array.isArray(legs) && legs.length ? legs[0] : {};
    const evidence = detail && detail.evidence ? detail.evidence : {};
    const verification = detail && detail.verification ? detail.verification : {};
    return reconstructionDescriptor({
      leg_structure_type: firstLeg.leg_structure_type || '',
      conflict_status: verification.conflict_status || '',
      verification_status: verification.verification_status || '',
      finalization_status: verification.finalization_status || '',
      fdm: evidence.fdm || {}
    });
  }

  function renderDetail(detail) {
    const summary = detail.summary || {};
    const identity = detail.identity || {};
    const legs = Array.isArray(detail.legs) ? detail.legs : [];
    detailTitle.textContent = 'Training Event';
    detailSubtitle.textContent = String((summary.aircraft && resolved(summary.aircraft)) || summary.aircraft || '') + ' · ' + String(summary.date || '');
    const crewItems = Array.isArray(detail.crew) ? detail.crew : Object.values(detail.crew || {});
    const legsHtml = legs.length ? '<div class="ml-grid">' + legs.map((leg, index) => {
      const route = resolved(leg.departure_airport) + ' to ' + resolved(leg.arrival_airport);
      const times = compactDate(resolved(leg.departure_local_time)) + ' - ' + compactDate(resolved(leg.arrival_local_time));
      return '<div class="ml-kv"><div class="ml-label">Flight segment ' + esc(index + 1) + '</div><div class="ml-value">' + esc(route) + '</div><div class="ml-muted">' + esc(times) + '</div><div style="margin-top:8px">' + chip(reconstructionDescriptor(leg)) + '</div></div>';
    }).join('') + '</div>' : '<div class="ml-muted">No segment detail rows returned.</div>';
    const transcript = detail.transcript || {};
    const map = detail.map || {};
    const activity = detailActivityDescriptor(detail);
    const reconstruction = detailReconstructionDescriptor(detail, legs);
    detailBody.innerHTML =
      '<section class="ml-section"><h3 style="margin:0">Summary</h3><div class="ml-grid">' +
        '<div class="ml-kv"><div class="ml-label">Activity</div><div style="margin-top:4px">' + chip(activity) + '</div></div>' +
        '<div class="ml-kv"><div class="ml-label">Reconstruction</div><div style="margin-top:4px">' + chip(reconstruction) + '</div></div>' +
        kv('Date', summary.date) + kv('Aircraft', resolved(summary.aircraft)) + kv('Start', summary.event_start) + kv('End', summary.event_end) +
      '</div></section>' +
      '<section class="ml-section"><h3 style="margin:0">Crew</h3><div>' + (crewItems.length ? crewItems.map((item) => esc(typeof item === 'object' ? resolved(item) : item)).join('<br>') : '<span class="ml-muted">Crew roles unresolved.</span>') + '</div></section>' +
      '<section class="ml-section"><h3 style="margin:0">Mission</h3><div>' + esc(typeof detail.mission === 'object' ? resolved(detail.mission) : (detail.mission || '--')) + '</div></section>' +
      '<section class="ml-section"><h3 style="margin:0">Legs</h3>' + legsHtml + '</section>' +
      '<section class="ml-section"><h3 style="margin:0">Evidence</h3>' + renderEvidenceMap(detail.evidence || {}) + '</section>' +
      '<section class="ml-section"><h3 style="margin:0">Transcript</h3><div class="ml-grid">' + kv('Raw Transcript', transcript.raw_transcript ? 'available' : 'not loaded') + kv('Enhanced Transcript', transcript.enhanced_transcript ? 'available' : 'not available') + kv('Enhancement Ownership', transcript.enhancement_ownership || 'not_traced') + '</div></section>' +
      '<section class="ml-section"><h3 style="margin:0">Replay Availability</h3>' + renderEvidenceMap({ replay: (detail.evidence || {}).replay || {} }) + '</section>' +
      '<section class="ml-section"><h3 style="margin:0">Proposal / Final Logbook</h3>' + renderEvidenceMap({ proposal: (detail.evidence || {}).proposal || {}, official_logbook: (detail.evidence || {}).official_logbook || {} }) + '</section>' +
      '<section class="ml-section"><h3 style="margin:0">Map References</h3><pre class="ml-pre">' + esc(JSON.stringify(map.track_references || [], null, 2)) + '</pre></section>' +
      '<section class="ml-section"><h3 style="margin:0">Operational Classification</h3><pre class="ml-pre">' + esc(JSON.stringify(detail.operational_classification || {}, null, 2)) + '</pre></section>';
  }

  async function openDetail(eventKey) {
    detailModal.classList.add('is-open');
    detailModal.setAttribute('aria-hidden', 'false');
    detailTitle.textContent = 'Training Event';
    detailSubtitle.textContent = 'Loading detail...';
    detailBody.innerHTML = '<div class="ml-muted">Loading detail...</div>';
    try {
      renderDetail(await fetchDetail(eventKey));
    } catch (error) {
      detailBody.innerHTML = '<div class="ml-error">' + esc(error.message || error) + '</div>';
    }
  }

  root.addEventListener('click', (event) => {
    const row = event.target.closest('.ml-row');
    if (row) {
      toggleExpanded(row);
    }
  });

  root.querySelectorAll('[data-view]').forEach((button) => {
    button.addEventListener('click', () => {
      state.view = button.getAttribute('data-view') || 'normal';
      state.page = 1;
      root.querySelectorAll('[data-view]').forEach((b) => b.classList.toggle('is-active', b === button));
      root.querySelector('[data-view-title]').textContent = state.view === 'unresolved' ? 'Unresolved Records' : 'Training Events';
      root.querySelector('[data-view-description]').textContent = state.view === 'unresolved'
        ? 'Orphan recordings, unmatched Garmin records, simulator-only sessions, and non-flight resources.'
        : 'Resolved operational Training Events only. Orphan evidence stays out of this view.';
      loadRows();
    });
  });

  root.querySelector('[data-page-size]').addEventListener('change', (event) => {
    state.pageSize = Number(event.target.value || 25);
    state.page = 1;
    loadRows();
  });
  root.querySelector('[data-prev]').addEventListener('click', () => { if (state.page > 1) { state.page -= 1; loadRows(); } });
  root.querySelector('[data-next]').addEventListener('click', () => { if (state.page < state.totalPages) { state.page += 1; loadRows(); } });
  root.querySelectorAll('[data-sort]').forEach((button) => {
    button.addEventListener('click', () => {
      const field = button.getAttribute('data-sort') || 'date';
      if (state.sortField === field) {
        state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
      } else {
        state.sortField = field;
        state.sortDirection = field === 'date' ? 'desc' : 'asc';
      }
      state.page = 1;
      loadRows();
    });
  });

  document.querySelector('[data-close-detail]').addEventListener('click', () => {
    detailModal.classList.remove('is-open');
    detailModal.setAttribute('aria-hidden', 'true');
  });
  detailModal.addEventListener('click', (event) => {
    if (event.target === detailModal) {
      detailModal.classList.remove('is-open');
      detailModal.setAttribute('aria-hidden', 'true');
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      detailModal.classList.remove('is-open');
      detailModal.setAttribute('aria-hidden', 'true');
    }
  });

  loadRows();
})();
</script>
<?php cw_footer(); ?>
