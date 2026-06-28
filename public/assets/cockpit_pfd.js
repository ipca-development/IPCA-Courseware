/* global Cesium */
(function () {
  'use strict';

  const PFD_W = 1280;
  const PFD_H = 768;
  const FLIGHT_W = 1182;
  const FLIGHT_H = 496;
  const TAPE_CENTER_Y = 211; // center of tape wrap (422/2)
  const PITCH_PX_PER_DEG = 8.5;
  const ATT_CX = FLIGHT_W / 2;
  const ATT_CY = 26 + (FLIGHT_H - 26) / 2; // below AP bar

  const fmtNum = (v, d = 0) => (v === null || v === undefined || Number.isNaN(Number(v)) ? '--' : Number(v).toFixed(d));
  const fmtFreq = (v) => {
    if (v === null || v === undefined || v === '') return '---.---';
    const n = Number(v);
    if (!Number.isFinite(n)) return String(v);
    return n.toFixed(3);
  };
  const normDeg = (d) => ((Number(d) % 360) + 360) % 360;
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

  const ZONE_COLORS = { white: '#ddd', green: '#0a0', yellow: '#cc0', red: '#c00' };

  function zoneAlertClass(value, zones) {
    if (value === null || value === undefined || !Number.isFinite(Number(value)) || !zones) return '';
    const v = Number(value);
    for (const z of zones) {
      if (v >= Number(z.from) && v <= Number(z.to) && (z.color === 'red' || z.color === 'yellow')) {
        return z.color === 'red' ? 'alert-red' : 'alert-yellow';
      }
    }
    return '';
  }

  function valuePct(value, min, max) {
    if (value === null || !Number.isFinite(Number(value))) return null;
    return clamp((Number(value) - min) / Math.max(0.001, max - min), 0, 1);
  }

  function buildZoneSegments(zones, min, max) {
    return (zones || []).map((z) => {
      const span = Math.max(0, Number(z.to) - Number(z.from));
      const pct = (span / Math.max(0.001, max - min)) * 100;
      return `<span class="zone ${z.color}" style="width:${pct.toFixed(2)}%"></span>`;
    }).join('');
  }

  function formatAltBox(alt) {
    if (!Number.isFinite(Number(alt))) return '<span class="hundreds">--</span>';
    const rounded = Math.round(Number(alt));
    const sign = rounded < 0 ? '-' : '';
    const abs = Math.abs(rounded);
    const hundreds = Math.floor(abs / 100);
    const tens = abs % 100;
    if (abs < 100) {
      return `${sign}<span class="hundreds">${abs}</span>`;
    }
    return `${sign ? `<span class="sign">${sign}</span>` : ''}<span class="hundreds">${hundreds}</span><span class="tens">${String(tens).padStart(2, '0')}</span>`;
  }

  function navSourceLabel(g3x) {
    const src = (g3x.nav_source || '').trim();
    const freq = g3x.nav2_mhz ? fmtFreq(g3x.nav2_mhz) : null;
    if (src && freq) return `${src.toUpperCase()} ${freq}`;
    if (src) return src.toUpperCase();
    if (freq) return `NAV2 ${freq}`;
    return '';
  }

  function renderEngineCol(profile, g3x, avail) {
    const gauges = profile.gauges || {};
    const keys = [
      ['rpm', 'rpm', 'arc'],
      ['gph', 'fuel_flow_gph', 'bar'],
      ['oil_psi', 'oil_psi', 'bar'],
      ['oil_temp_f', 'oil_temp_f', 'bar'],
      ['egt1_f', 'egt1_f', 'bar'],
      ['egt2_f', 'egt2_f', 'bar'],
      ['fuel_gal', 'fuel_gal', 'bar'],
      ['fuel_psi', 'fuel_psi', 'bar'],
      ['coolant1_f', 'coolant1_f', 'bar'],
      ['coolant2_f', 'coolant2_f', 'bar'],
      ['volts', 'volts', 'bar'],
      ['amps', 'amps', 'bar'],
    ];

    return keys.map(([key, field, type]) => {
      const cfg = gauges[key] || {};
      const label = cfg.label || key.toUpperCase();
      const min = Number(cfg.min ?? 0);
      const max = Number(cfg.max ?? 100);
      const hasData = avail[field] !== false;
      const val = hasData ? g3x[field] : null;

      if (!hasData) {
        return `<div class="g3x-gauge-bar"><span class="lbl">${label}</span><div class="g3x-gauge-unavail"></div></div>`;
      }

      if (type === 'arc') {
        const pct = valuePct(val, min, max) ?? 0;
        const angle = -135 + pct * 270;
        const alert = zoneAlertClass(val, cfg.zones);
        return `<div class="g3x-gauge-arc">
          <svg viewBox="0 0 90 68" aria-hidden="true">
            <path d="M12 58 A36 36 0 1 1 78 58" fill="none" stroke="#333" stroke-width="6"/>
            ${(cfg.zones || []).map((z) => {
              const a1 = -135 + ((Number(z.from) - min) / (max - min)) * 270;
              const a2 = -135 + ((Number(z.to) - min) / (max - min)) * 270;
              const r = 36; const cx = 45; const cy = 58;
              const rad = (deg) => (deg * Math.PI) / 180;
              const x1 = cx + r * Math.cos(rad(a1));
              const y1 = cy + r * Math.sin(rad(a1));
              const x2 = cx + r * Math.cos(rad(a2));
              const y2 = cy + r * Math.sin(rad(a2));
              const large = Math.abs(a2 - a1) > 180 ? 1 : 0;
              return `<path d="M${x1.toFixed(1)} ${y1.toFixed(1)} A${r} ${r} 0 ${large} 1 ${x2.toFixed(1)} ${y2.toFixed(1)}" fill="none" stroke="${ZONE_COLORS[z.color] || '#fff'}" stroke-width="5"/>`;
            }).join('')}
            <line x1="45" y1="58" x2="${(45 + 28 * Math.cos((angle * Math.PI) / 180)).toFixed(1)}" y2="${(58 + 28 * Math.sin((angle * Math.PI) / 180)).toFixed(1)}" stroke="#fff" stroke-width="2"/>
          </svg>
          <div class="lbl ${alert}">${label}</div>
          <div class="val ${alert}">${fmtNum(val, 0)}</div>
        </div>`;
      }

      const pct = valuePct(val, min, max);
      const alert = zoneAlertClass(val, cfg.zones);
      const pointer = pct === null ? '' : `<span class="pointer" style="left:${(pct * 100).toFixed(1)}%"></span>`;
      return `<div class="g3x-gauge-bar">
        <span class="lbl ${alert}">${label}</span>
        <div class="track"><div class="zones">${buildZoneSegments(cfg.zones, min, max)}</div>${pointer}</div>
        <span class="val ${alert}">${fmtNum(val, key === 'gph' ? 1 : 0)}</span>
      </div>`;
    }).join('');
  }

  function renderIasTape(profile, ias, tas, gs) {
    const tape = profile.airspeed_tape || { min: 20, max: 200, major: 10, minor: 2 };
    const min = Number(tape.min ?? 20);
    const max = Number(tape.max ?? 200);
    const major = Number(tape.major ?? 10);
    const minor = Number(tape.minor ?? 2);
    const pxPerKt = 20 / major;
    const current = Number.isFinite(Number(ias)) ? Number(ias) : min;
    const offset = current * pxPerKt;
    const tapeHeight = (max - min) * pxPerKt;

    let ticks = '';
    for (let v = min; v <= max; v += minor) {
      const isMajor = v % major === 0;
      const top = (max - v) * pxPerKt;
      ticks += `<div class="g3x-ias-tick ${isMajor ? 'major' : 'minor'}" style="top:${top}px">${isMajor ? v : ''}</div>`;
    }

    const arc = (profile.airspeed_arc || []).map((seg) => {
      const top = (max - Number(seg.to)) * pxPerKt;
      const h = (Number(seg.to) - Number(seg.from)) * pxPerKt;
      return `<div style="position:absolute;left:0;top:${top}px;height:${h}px;width:100%;background:${ZONE_COLORS[seg.color] || '#fff'}"></div>`;
    }).join('');

    const bugs = Object.entries(profile.v_speeds || {}).map(([k, v]) => {
      const y = (max - Number(v)) * pxPerKt;
      const letter = k.replace(/^V/i, '').toUpperCase();
      return `<div class="g3x-ias-bug-row" style="top:${y}px;transform:translateY(-50%)">
        <span>${v}</span><span class="g3x-ias-bug-mark"></span><span>${letter}</span>
      </div>`;
    }).join('');

    const translateY = TAPE_CENTER_Y - offset;

    return `<div class="g3x-ias-tas"><span>TAS</span><span>${Number.isFinite(Number(tas)) ? `${fmtNum(tas, 0)}KT` : '----KT'}</span></div>
      <div class="g3x-ias-tape-wrap">
        <div class="g3x-ias-tape" style="height:${tapeHeight}px;transform:translateY(${translateY}px)">
          ${ticks}
          <div class="g3x-ias-arc">${arc}</div>
          <div class="g3x-ias-bugs-layer">${bugs}</div>
        </div>
        <div class="g3x-ias-box">${Number.isFinite(Number(ias)) ? fmtNum(ias, 0) : '---'}</div>
      </div>
      <div class="g3x-ias-gs"><span>GS</span><span class="val">${Number.isFinite(Number(gs)) ? `${fmtNum(gs, 0)}KT` : '--KT'}</span></div>`;
  }

  function renderAltTape(profile, alt, bug, baro, vs) {
    const tape = profile.altitude_tape || { min: -1000, max: 20000, major: 100, minor: 20 };
    const min = Number(tape.min ?? -1000);
    const max = Number(tape.max ?? 20000);
    const major = Number(tape.major ?? 100);
    const pxPerFt = 20 / major;
    const current = Number.isFinite(Number(alt)) ? Number(alt) : 0;
    const offset = current * pxPerFt;
    const tapeHeight = (max - min) * pxPerFt;
    const da = profile.decision_altitude_ft;

    let ticks = '';
    for (let v = Math.floor(min / major) * major; v <= max; v += Number(tape.minor ?? 20)) {
      if (v % major !== 0) continue;
      const top = (max - v) * pxPerFt;
      const label = v < 0 ? `-${Math.abs(v)}` : String(v);
      ticks += `<div class="g3x-alt-tick major" style="top:${top}px">${label}</div>`;
    }

    const baroLabel = profile.units?.altimeter === 'hPa'
      ? `${Number.isFinite(Number(baro)) ? (Number(baro) * 33.8639).toFixed(0) : '--'} HPA`
      : `${Number.isFinite(Number(baro)) ? fmtNum(baro, 2) : '--'} IN`;

    const vsiVal = Number.isFinite(Number(vs)) ? Number(vs) : 0;
    const vsiTop = clamp(50 - (vsiVal / 2000) * 45, 5, 95);
    const translateY = TAPE_CENTER_Y - offset;

    const bugLabel = Number.isFinite(Number(bug)) ? `${Math.round(Number(bug))} FT` : '---- FT';
    const daLabel = da !== null && da !== undefined && Number.isFinite(Number(da))
      ? `DA ${Math.round(Number(da))}FT`
      : '';

    return `<div class="g3x-alt-stack">
      <div class="g3x-alt-bug">${bugLabel}</div>
      <div class="g3x-alt-tape-wrap">
        <div class="g3x-alt-tape" style="height:${tapeHeight}px;transform:translateY(${translateY}px)">${ticks}</div>
        <div class="g3x-alt-box">${formatAltBox(current)}</div>
      </div>
      ${daLabel ? `<div class="g3x-alt-da">${daLabel}</div>` : ''}
      <div class="g3x-alt-baro">${baroLabel}</div>
    </div>
    <div class="g3x-vsi-wrap">
      <svg class="g3x-vsi-scale" viewBox="0 0 38 374" aria-hidden="true">
        <line x1="19" y1="187" x2="19" y2="18" stroke="rgba(255,255,255,.5)" stroke-width="1"/>
        <line x1="19" y1="187" x2="19" y2="356" stroke="rgba(255,255,255,.5)" stroke-width="1"/>
        <text x="22" y="189" fill="#fff" font-size="10">0</text>
        <text x="22" y="132" fill="#fff" font-size="10">1</text>
        <text x="22" y="75" fill="#fff" font-size="10">2</text>
        <text x="22" y="245" fill="#fff" font-size="10">1</text>
        <text x="22" y="302" fill="#fff" font-size="10">2</text>
      </svg>
      <div class="g3x-vsi-pointer" style="top:${vsiTop}%"></div>
    </div>`;
  }

  function renderHsi(heading, hdgBug, course, navSource, g3x) {
    const hdg = normDeg(heading ?? 0);
    const bug = hdgBug !== null && hdgBug !== undefined ? normDeg(hdgBug) : null;
    const crs = course !== null && course !== undefined ? normDeg(course) : null;
    const bearing = g3x.nav_bearing_deg !== null && g3x.nav_bearing_deg !== undefined
      ? normDeg(g3x.nav_bearing_deg) : null;
    const isGps = navSource && /gps|fms|wpt/i.test(String(navSource));
    const crsColor = isGps ? '#ff39ff' : '#39ff39';
    const navLabel = navSourceLabel(g3x);
    const cx = 170; const cy = 170;

    let rose = '';
    for (let d = 0; d < 360; d += 10) {
      const a = ((d - hdg) * Math.PI) / 180;
      const r1 = 125; const r2 = d % 30 === 0 ? 106 : 114;
      const x1 = cx + r1 * Math.sin(a);
      const y1 = cy - r1 * Math.cos(a);
      const x2 = cx + r2 * Math.sin(a);
      const y2 = cy - r2 * Math.cos(a);
      rose += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#fff" stroke-width="${d % 30 === 0 ? 2 : 1}"/>`;
      if (d % 30 === 0) {
        const lx = cx + 92 * Math.sin(a);
        const ly = cy - 92 * Math.cos(a);
        const label = d === 0 ? 'N' : d === 90 ? 'E' : d === 180 ? 'S' : d === 270 ? 'W' : String(d / 10);
        rose += `<text x="${lx}" y="${ly + 4}" fill="#fff" font-size="13" text-anchor="middle">${label}</text>`;
      }
    }

    let crsNeedle = '';
    if (crs !== null) {
      const a = ((crs - hdg) * Math.PI) / 180;
      const x = cx + 98 * Math.sin(a);
      const y = cy - 98 * Math.cos(a);
      const bx1 = cx + 18 * Math.sin(a + Math.PI / 2);
      const by1 = cy - 18 * Math.cos(a + Math.PI / 2);
      const bx2 = cx + 18 * Math.sin(a - Math.PI / 2);
      const by2 = cy - 18 * Math.cos(a - Math.PI / 2);
      crsNeedle = `<line x1="${bx1}" y1="${by1}" x2="${bx2}" y2="${by2}" stroke="${crsColor}" stroke-width="5"/>
        <line x1="${cx}" y1="${cy}" x2="${x}" y2="${y}" stroke="${crsColor}" stroke-width="3"/>
        <polygon points="${x},${y} ${x - 10 * Math.cos(a)},${y - 10 * Math.sin(a)} ${x + 12 * Math.sin(a)},${y - 12 * Math.cos(a)}" fill="${crsColor}"/>`;
    }

    let brgNeedle = '';
    if (bearing !== null) {
      const a = ((bearing - hdg) * Math.PI) / 180;
      const x = cx + 88 * Math.sin(a);
      const y = cy - 88 * Math.cos(a);
      brgNeedle = `<line x1="${cx}" y1="${cy}" x2="${x}" y2="${y}" stroke="#0ff" stroke-width="2"/>
        <polygon points="${x},${y} ${x - 6 * Math.cos(a)},${y - 6 * Math.sin(a)} ${x + 8 * Math.sin(a)},${y - 8 * Math.cos(a)}" fill="#0ff"/>`;
    }

    let bugMark = '';
    if (bug !== null) {
      const a = ((bug - hdg) * Math.PI) / 180;
      const x = cx + 128 * Math.sin(a);
      const y = cy - 128 * Math.cos(a);
      bugMark = `<polygon points="${x},${y - 7} ${x - 7},${y + 5} ${x + 7},${y + 5}" fill="#0ff"/>`;
    }

    const navText = navLabel
      ? `<text x="${cx}" y="${cy + 28}" fill="${crsColor}" font-size="13" text-anchor="middle">${navLabel}</text>`
      : '';

    return `<svg viewBox="0 0 340 340" aria-label="HSI">
      <circle cx="${cx}" cy="${cy}" r="132" fill="rgba(0,0,0,.5)" stroke="rgba(255,255,255,.3)" stroke-width="2"/>
      <g transform="rotate(${-hdg} ${cx} ${cy})">${rose}${crsNeedle}${brgNeedle}${bugMark}</g>
      <path d="M${cx} ${cy - 14} L${cx - 10} ${cy + 10} L${cx + 10} ${cy + 10} Z" fill="#fff"/>
      <path d="M${cx - 6} ${cy + 4} L${cx} ${cy + 12} L${cx + 6} ${cy + 4} Z" fill="#fff"/>
      ${navText}
      <rect x="${cx - 24}" y="14" width="48" height="24" rx="2" fill="#000" stroke="#fff" stroke-width="1"/>
      <text x="${cx}" y="31" fill="#fff" font-size="15" text-anchor="middle">${String(Math.round(hdg)).padStart(3, '0')}°</text>
    </svg>`;
  }

  function renderAttitudeSvg(pitch, roll, slip, g3x) {
    const p = Number(pitch) || 0;
    const r = Number(roll) || 0;
    const slipX = clamp((Number(slip) || 0) * 100, -44, 44);
    const cx = ATT_CX;
    const cy = ATT_CY;

    // FD chevrons offset from H/V CDI (scale to pixels)
    const hcdi = Number(g3x?.hcdi) || 0;
    const vcdi = Number(g3x?.vcdi) || 0;
    const fdX = clamp(hcdi * 55, -80, 80);
    const fdY = clamp(-vcdi * 55, -60, 60);

    let ladder = '';
    for (let deg = -30; deg <= 30; deg += 5) {
      const y = cy - deg * PITCH_PX_PER_DEG;
      const w = deg % 10 === 0 ? 140 : 70;
      ladder += `<line x1="${cx - w / 2}" y1="${y}" x2="${cx + w / 2}" y2="${y}" stroke="#fff" stroke-width="${deg % 10 === 0 ? 2 : 1}"/>`;
      if (deg % 10 === 0 && deg !== 0) {
        ladder += `<text x="${cx + w / 2 + 6}" y="${y + 4}" fill="#fff" font-size="11">${Math.abs(deg)}</text>`;
      }
    }
    // Horizon line (0 pitch)
    ladder += `<line x1="${cx - 200}" y1="${cy}" x2="${cx + 200}" y2="${cy}" stroke="#fff" stroke-width="2"/>`;

    const bankMarks = [-60, -45, -30, -20, -10, 0, 10, 20, 30, 45, 60];
    let bankArc = '';
    bankMarks.forEach((deg) => {
      const a = ((deg - 90) * Math.PI) / 180;
      const r0 = 168;
      const len = Math.abs(deg) >= 30 ? 18 : 11;
      const x1 = cx + r0 * Math.cos(a);
      const y1 = cy - 120 + r0 * Math.sin(a);
      const x2 = cx + (r0 - len) * Math.cos(a);
      const y2 = cy - 120 + (r0 - len) * Math.sin(a);
      bankArc += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#fff" stroke-width="${Math.abs(deg) >= 30 ? 3 : 2}"/>`;
    });

    const slipY = cy + 148;

    return `<svg viewBox="0 0 ${FLIGHT_W} ${FLIGHT_H}" aria-hidden="true">
      <g transform="translate(${cx} ${cy}) rotate(${-r}) translate(${-cx} ${-cy})">
        <g transform="translate(0 ${p * PITCH_PX_PER_DEG})">${ladder}</g>
      </g>
      <g>${bankArc}<polygon points="${cx},${cy - 118} ${cx - 10},${cy - 98} ${cx + 10},${cy - 98}" fill="#fff"/></g>
      <g transform="translate(${cx + fdX} ${cy + fdY - 8})">
        <path d="M-16 0 L-6 0 L0 -14 L6 0 L16 0" fill="none" stroke="#ff39ff" stroke-width="3"/>
        <path d="M-8 6 L0 16 L8 6" fill="none" stroke="#ff39ff" stroke-width="2.5"/>
      </g>
      <g transform="translate(${cx} ${cy + 6})">
        <path d="M-72 0 L-18 0 L0 -16 L18 0 L72 0" fill="none" stroke="#ffd400" stroke-width="4"/>
        <path d="M-14 8 L0 22 L14 8" fill="none" stroke="#ffd400" stroke-width="3"/>
      </g>
      <g transform="translate(${cx} ${slipY})">
        <rect x="-58" y="-11" width="116" height="22" rx="11" fill="rgba(0,0,0,.5)" stroke="rgba(255,255,255,.4)" stroke-width="1"/>
        <line x1="-16" y1="-9" x2="-16" y2="9" stroke="#fff" stroke-width="2"/>
        <line x1="16" y1="-9" x2="16" y2="9" stroke="#fff" stroke-width="2"/>
        <circle cx="${slipX}" cy="0" r="9" fill="#fff"/>
      </g>
    </svg>`;
  }

  function renderApModes(g3x) {
    const modes = [];
    const ap = (g3x.ap_state || '').trim();
    const lat = (g3x.fd_lat_mode || '').trim();
    const vert = (g3x.fd_vert_mode || '').trim();
    if (/on|engaged|active/i.test(ap) || ap.toUpperCase() === 'AP') modes.push({ label: 'AP', active: true });
    else if (ap) modes.push({ label: ap.toUpperCase(), active: true });
    if (lat) modes.push({ label: lat.toUpperCase(), active: /hdg|nav|apr|track|rol/i.test(lat) });
    if (vert) modes.push({ label: vert.toUpperCase(), active: /alt|vs|glc|vnav|pit/i.test(vert) });
    if (!modes.length) return '<span class="g3x-ap-empty">AP | FD</span>';
    return modes.map((m, i) => `${i ? '<span class="g3x-ap-sep">|</span>' : ''}<span class="g3x-ap-mode${m.active ? ' active' : ''}">${m.label}</span>`).join('');
  }

  function renderRadios(g3x) {
    return `<div class="g3x-radio-box com"><span class="label">COM 1</span><span class="freq">${fmtFreq(g3x.com1_mhz)}</span></div>
      <div class="g3x-radio-box com"><span class="label">COM 2</span><span class="freq">${fmtFreq(g3x.com2_mhz)}</span></div>
      <div class="g3x-radio-box nav"><span class="label">NAV 2</span><span class="freq">${fmtFreq(g3x.nav2_mhz)}</span></div>
      <div class="g3x-radio-box xpdr"><span class="label">XPDR</span><span class="freq">${g3x.xpdr_code || '----'}</span></div>`;
  }

  function computeG3xAvailability(samples) {
    const fields = ['rpm', 'fuel_flow_gph', 'oil_psi', 'oil_temp_f', 'egt1_f', 'egt2_f', 'fuel_gal', 'fuel_psi', 'coolant1_f', 'coolant2_f', 'volts', 'amps'];
    const avail = {};
    fields.forEach((f) => { avail[f] = false; });
    for (const s of samples || []) {
      const g = s.g3x || {};
      fields.forEach((f) => {
        if (g[f] !== null && g[f] !== undefined && Number.isFinite(Number(g[f]))) avail[f] = true;
      });
    }
    return avail;
  }

  function buildDom() {
    return `<div class="g3x-pfd-shell"><div class="g3x-pfd-frame"><div class="g3x-pfd" id="g3xPfdRoot">
      <div class="g3x-engine-col" id="g3xEngine"></div>
      <div class="g3x-top-bar" id="g3xTop"></div>
      <div class="g3x-main-display">
        <div class="g3x-sv-layer"><div class="cesium-cockpit" id="cesiumReplay"></div></div>
        <div class="g3x-ground-fill"></div>
        <div class="g3x-flight-stack">
          <div class="g3x-ap-bar" id="g3xAp"></div>
          <div class="g3x-attitude-overlay" id="g3xAttitude"></div>
          <div class="g3x-ias-col" id="g3xIas"></div>
          <div class="g3x-alt-col" id="g3xAlt"></div>
        </div>
        <div class="g3x-bottom-bar">
          <div class="g3x-wind-box" id="g3xWind"></div>
          <div class="g3x-hsi-stack">
            <div class="g3x-hsi-bugs">
              <div class="g3x-hsi-bug-left" id="g3xHdgBug"></div>
              <div class="g3x-hsi-bug-right" id="g3xCrsBug"></div>
            </div>
            <div class="g3x-hsi-wrap" id="g3xHsi"></div>
          </div>
          <div class="g3x-oat-box" id="g3xOat"></div>
        </div>
      </div>
    </div></div></div>`;
  }

  function scalePfd() {
    const frame = document.querySelector('.g3x-pfd-frame');
    const pfd = document.getElementById('g3xPfdRoot');
    if (!frame || !pfd) return;
    const scale = frame.clientWidth / PFD_W;
    pfd.style.transform = `scale(${scale})`;
    frame.style.height = `${PFD_H * scale}px`;
  }

  const CockpitPfd = {
    profile: null,
    g3xAvail: {},
    mount(container) {
      container.innerHTML = buildDom();
      window.addEventListener('resize', scalePfd);
      scalePfd();
    },
    setProfile(profile) {
      this.profile = profile || {};
    },
    setSamples(samples) {
      this.g3xAvail = computeG3xAvailability(samples);
    },
    render(sample) {
      if (!sample || !this.profile) return;
      const g = sample.g3x || {};
      const p = this.profile;
      const el = (id) => document.getElementById(id);

      if (el('g3xEngine')) el('g3xEngine').innerHTML = renderEngineCol(p, g, this.g3xAvail);
      if (el('g3xTop')) el('g3xTop').innerHTML = renderRadios(g);
      if (el('g3xAp')) el('g3xAp').innerHTML = renderApModes(g);
      if (el('g3xIas')) el('g3xIas').innerHTML = renderIasTape(p, sample.ias_kt, sample.estimated_tas_kt ?? g.tas_kt, sample.groundspeed_kt);
      if (el('g3xAlt')) {
        el('g3xAlt').innerHTML = renderAltTape(
          p,
          sample.baro_altitude_ft ?? sample.estimated_indicated_altitude_ft ?? g.baro_alt_ft,
          sample.altitude_bug_ft ?? g.sel_alt_ft,
          sample.altimeter_setting_inhg ?? g.baro_inhg,
          sample.vertical_speed_fpm ?? sample.estimated_vertical_speed_fpm ?? g.vs_fpm
        );
      }
      if (el('g3xAttitude')) el('g3xAttitude').innerHTML = renderAttitudeSvg(sample.pitch_deg, sample.bank_deg, sample.estimated_slip_skid_g ?? g.slip_g, g);
      if (el('g3xHsi')) el('g3xHsi').innerHTML = renderHsi(sample.heading_deg, sample.heading_bug_deg ?? g.sel_hdg_deg, g.nav_course_deg, g.nav_source, g);
      if (el('g3xHdgBug')) {
        const hdg = sample.heading_bug_deg ?? g.sel_hdg_deg;
        el('g3xHdgBug').textContent = hdg !== null && hdg !== undefined ? `HDG ${Math.round(hdg)}°` : '';
      }
      if (el('g3xCrsBug')) {
        el('g3xCrsBug').textContent = g.nav_course_deg !== null && g.nav_course_deg !== undefined ? `CRS ${Math.round(g.nav_course_deg)}°` : '';
      }

      const windSpd = sample.estimated_wind_speed_kt ?? g.wind_speed_kt;
      const windDir = sample.estimated_wind_direction_deg_true ?? g.wind_dir_deg;
      if (el('g3xWind')) {
        el('g3xWind').innerHTML = Number.isFinite(Number(windSpd)) && Number(windSpd) > 0
          ? `${Math.round(windDir ?? 0)}° / ${fmtNum(windSpd, 0)} KT`
          : 'NO WIND DATA';
      }
      if (el('g3xOat')) {
        const oat = sample.oat_c ?? g.oat_c;
        el('g3xOat').innerHTML = Number.isFinite(Number(oat)) ? `OAT ${fmtNum(oat, 0)}°C` : '';
      }
    },
    scale: scalePfd,
  };

  window.CockpitPfd = CockpitPfd;
})();
