/* global Cesium */
(function () {
  'use strict';

  const PFD_W = 1280;
  const PFD_H = 768;

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
          <svg viewBox="0 0 90 72" aria-hidden="true">
            <path d="M12 62 A36 36 0 1 1 78 62" fill="none" stroke="#333" stroke-width="6"/>
            ${(cfg.zones || []).map((z) => {
              const a1 = -135 + ((Number(z.from) - min) / (max - min)) * 270;
              const a2 = -135 + ((Number(z.to) - min) / (max - min)) * 270;
              const r = 36; const cx = 45; const cy = 62;
              const rad = (deg) => (deg * Math.PI) / 180;
              const x1 = cx + r * Math.cos(rad(a1));
              const y1 = cy + r * Math.sin(rad(a1));
              const x2 = cx + r * Math.cos(rad(a2));
              const y2 = cy + r * Math.sin(rad(a2));
              const large = Math.abs(a2 - a1) > 180 ? 1 : 0;
              return `<path d="M${x1.toFixed(1)} ${y1.toFixed(1)} A${r} ${r} 0 ${large} 1 ${x2.toFixed(1)} ${y2.toFixed(1)}" fill="none" stroke="${ZONE_COLORS[z.color] || '#fff'}" stroke-width="5"/>`;
            }).join('')}
            <line x1="45" y1="62" x2="${(45 + 28 * Math.cos((angle * Math.PI) / 180)).toFixed(1)}" y2="${(62 + 28 * Math.sin((angle * Math.PI) / 180)).toFixed(1)}" stroke="#fff" stroke-width="2"/>
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
    const pxPerKt = 20 / major;
    const current = Number.isFinite(Number(ias)) ? Number(ias) : min;
    const offset = current * pxPerKt;

    let ticks = '';
    for (let v = min; v <= max; v += Number(tape.minor ?? 2)) {
      const isMajor = v % major === 0;
      ticks += `<div class="g3x-ias-tick ${isMajor ? 'major' : 'minor'}" style="top:${(max - v) * pxPerKt}px">${isMajor ? v : ''}</div>`;
    }

    const arc = (profile.airspeed_arc || []).map((seg) => {
      const top = (max - Number(seg.to)) * pxPerKt;
      const h = (Number(seg.to) - Number(seg.from)) * pxPerKt;
      return `<div style="position:absolute;right:0;top:${top}px;height:${h}px;width:100%;background:${ZONE_COLORS[seg.color] || '#fff'};opacity:0.85"></div>`;
    }).join('');

    const bugs = Object.entries(profile.v_speeds || {}).map(([k, v]) => {
      const y = (max - Number(v)) * pxPerKt - offset + 198;
      return `<div class="g3x-ias-bug-row" style="position:absolute;right:0;top:${y}px;transform:translateY(-50%)">
        <span>${v}</span><span class="g3x-ias-bug-mark"></span><span>${k.replace('V', '')}</span>
      </div>`;
    }).join('');

    return `<div class="g3x-ias-tas"><span>TAS</span><span>${Number.isFinite(Number(tas)) ? `${fmtNum(tas, 0)} KT` : '---- KT'}</span></div>
      <div class="g3x-ias-tape-wrap">
        <div class="g3x-ias-arc">${arc}</div>
        <div class="g3x-ias-tape" style="transform:translateY(calc(-${offset}px + 198px))">${ticks}</div>
        <div class="g3x-ias-box">${Number.isFinite(Number(ias)) ? fmtNum(ias, 0) : '--'}</div>
        <div class="g3x-ias-bugs">${bugs}</div>
      </div>
      <div class="g3x-ias-gs"><span>GS</span><span class="val">${Number.isFinite(Number(gs)) ? `${fmtNum(gs, 0)} KT` : '-- KT'}</span></div>`;
  }

  function renderAltTape(profile, alt, bug, baro, vs) {
    const tape = profile.altitude_tape || { min: -1000, max: 20000, major: 100, minor: 20 };
    const min = Number(tape.min ?? -1000);
    const max = Number(tape.max ?? 20000);
    const major = Number(tape.major ?? 100);
    const pxPerFt = 20 / major;
    const current = Number.isFinite(Number(alt)) ? Number(alt) : 0;
    const offset = current * pxPerFt;

    let ticks = '';
    for (let v = Math.floor(min / major) * major; v <= max; v += Number(tape.minor ?? 20)) {
      const isMajor = v % major === 0;
      if (!isMajor) continue;
      ticks += `<div class="g3x-alt-tick major" style="top:${(max - v) * pxPerFt}px">${Math.abs(v)}</div>`;
    }

    const hundreds = Math.floor(Math.abs(current) / 100);
    const tens = Math.abs(Math.round(current)) % 100;
    const baroLabel = profile.units?.altimeter === 'hPa'
      ? `${Number.isFinite(Number(baro)) ? (Number(baro) * 33.8639).toFixed(0) : '--'} HPA`
      : `${Number.isFinite(Number(baro)) ? fmtNum(baro, 2) : '--'} IN`;

    const vsiVal = Number.isFinite(Number(vs)) ? Number(vs) : 0;
    const vsiTop = clamp(50 - (vsiVal / 2000) * 45, 5, 95);

    return `<div class="g3x-alt-stack"><div class="g3x-alt-bug">${Number.isFinite(Number(bug)) ? `${fmtNum(bug, 0)} FT` : '---- FT'}</div>
      <div class="g3x-alt-tape-wrap">
        <div class="g3x-alt-tape" style="transform:translateY(calc(-${offset}px + 198px))">${ticks}</div>
        <div class="g3x-alt-box"><span class="tens">${hundreds}</span>${String(tens).padStart(2, '0')}</div>
      </div>
      <div class="g3x-alt-baro">${baroLabel}</div></div>
      <div class="g3x-vsi-wrap">
        <svg class="g3x-vsi-scale" viewBox="0 0 38 396" aria-hidden="true">
          <line x1="19" y1="198" x2="19" y2="20" stroke="rgba(255,255,255,.5)" stroke-width="1"/>
          <line x1="19" y1="198" x2="19" y2="376" stroke="rgba(255,255,255,.5)" stroke-width="1"/>
          <text x="22" y="200" fill="#fff" font-size="10">0</text>
          <text x="22" y="140" fill="#fff" font-size="10">1</text>
          <text x="22" y="80" fill="#fff" font-size="10">2</text>
          <text x="22" y="260" fill="#fff" font-size="10">1</text>
          <text x="22" y="320" fill="#fff" font-size="10">2</text>
        </svg>
        <div class="g3x-vsi-pointer" style="top:${vsiTop}%"></div>
      </div>`;
  }

  function renderHsi(heading, hdgBug, course, navSource) {
    const hdg = normDeg(heading ?? 0);
    const bug = hdgBug !== null && hdgBug !== undefined ? normDeg(hdgBug) : null;
    const crs = course !== null && course !== undefined ? normDeg(course) : null;
    const isGps = navSource && /gps|fms|wpt/i.test(String(navSource));
    const crsColor = isGps ? '#ff39ff' : '#39ff39';

    let rose = '';
    for (let d = 0; d < 360; d += 10) {
      const a = ((d - hdg) * Math.PI) / 180;
      const cx = 160; const cy = 160; const r1 = 118; const r2 = d % 30 === 0 ? 100 : 108;
      const x1 = cx + r1 * Math.sin(a);
      const y1 = cy - r1 * Math.cos(a);
      const x2 = cx + r2 * Math.sin(a);
      const y2 = cy - r2 * Math.cos(a);
      rose += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#fff" stroke-width="${d % 30 === 0 ? 2 : 1}"/>`;
      if (d % 30 === 0) {
        const lx = cx + 88 * Math.sin(a);
        const ly = cy - 88 * Math.cos(a);
        const label = d === 0 ? 'N' : d === 90 ? 'E' : d === 180 ? 'S' : d === 270 ? 'W' : String(d / 10);
        rose += `<text x="${lx}" y="${ly + 4}" fill="#fff" font-size="14" text-anchor="middle">${label}</text>`;
      }
    }

    let crsNeedle = '';
    if (crs !== null) {
      const a = ((crs - hdg) * Math.PI) / 180;
      const cx = 160; const cy = 160;
      const x = cx + 95 * Math.sin(a);
      const y = cy - 95 * Math.cos(a);
      crsNeedle = `<line x1="${cx}" y1="${cy}" x2="${x}" y2="${y}" stroke="${crsColor}" stroke-width="4"/>
        <polygon points="${x},${y} ${x - 8 * Math.cos(a)},${y - 8 * Math.sin(a)} ${x + 10 * Math.sin(a)},${y - 10 * Math.cos(a)}" fill="${crsColor}"/>`;
    }

    let bugMark = '';
    if (bug !== null) {
      const a = ((bug - hdg) * Math.PI) / 180;
      const cx = 160; const cy = 160;
      const x = cx + 122 * Math.sin(a);
      const y = cy - 122 * Math.cos(a);
      bugMark = `<polygon points="${x},${y - 6} ${x - 6},${y + 4} ${x + 6},${y + 4}" fill="#0ff"/>`;
    }

    return `<svg viewBox="0 0 320 320" aria-label="HSI">
      <circle cx="160" cy="160" r="128" fill="rgba(0,0,0,.45)" stroke="rgba(255,255,255,.35)" stroke-width="2"/>
      <g transform="rotate(${-hdg} 160 160)">${rose}${crsNeedle}${bugMark}</g>
      <path d="M160 148 L150 168 L170 168 Z" fill="#fff"/>
      <rect x="138" y="18" width="44" height="26" rx="3" fill="#000" stroke="#fff"/>
      <text x="160" y="36" fill="#fff" font-size="16" text-anchor="middle">${String(Math.round(hdg)).padStart(3, '0')}°</text>
    </svg>`;
  }

  function renderAttitudeSvg(pitch, roll, slip) {
    const p = Number(pitch) || 0;
    const r = Number(roll) || 0;
    const slipX = clamp((Number(slip) || 0) * 120, -50, 50);

    let ladder = '';
    for (let deg = -30; deg <= 30; deg += 5) {
      const y = 240 - deg * 8;
      const w = deg % 10 === 0 ? 120 : 60;
      ladder += `<line x1="${334 - w / 2}" y1="${y}" x2="${334 + w / 2}" y2="${y}" stroke="#fff" stroke-width="${deg % 10 === 0 ? 2 : 1}"/>`;
      if (deg % 10 === 0 && deg !== 0) {
        ladder += `<text x="${334 + w / 2 + 8}" y="${y + 4}" fill="#fff" font-size="12">${Math.abs(deg)}</text>`;
      }
    }

    const bankMarks = [-60, -45, -30, -20, -10, 0, 10, 20, 30, 45, 60];
    let bankArc = '';
    bankMarks.forEach((deg) => {
      const a = ((deg - 90) * Math.PI) / 180;
      const cx = 334; const cy = 240; const r0 = 150;
      const len = Math.abs(deg) >= 30 ? 16 : 10;
      const x1 = cx + r0 * Math.cos(a);
      const y1 = cy + r0 * Math.sin(a);
      const x2 = cx + (r0 - len) * Math.cos(a);
      const y2 = cy + (r0 - len) * Math.sin(a);
      bankArc += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#fff" stroke-width="${Math.abs(deg) >= 30 ? 3 : 2}"/>`;
    });

    return `<svg viewBox="0 0 668 480" aria-hidden="true">
      <g transform="translate(334 240) rotate(${-r}) translate(-334 -240)">
        <g transform="translate(0 ${-p * 8})">${ladder}</g>
      </g>
      <g>${bankArc}<polygon points="334,78 324,98 344,98" fill="#fff"/></g>
      <g transform="translate(334 390)">
        <rect x="-66" y="-12" width="132" height="24" rx="12" fill="rgba(0,0,0,.55)" stroke="rgba(255,255,255,.45)"/>
        <line x1="-18" y1="-10" x2="-18" y2="10" stroke="#fff" stroke-width="2"/>
        <line x1="18" y1="-10" x2="18" y2="10" stroke="#fff" stroke-width="2"/>
        <circle cx="${slipX}" cy="0" r="10" fill="#fff"/>
      </g>
      <g transform="translate(334 250)">
        <path d="M-70 0 L-20 0 L0 -18 L20 0 L70 0" fill="none" stroke="#ffd400" stroke-width="4"/>
        <path d="M-12 8 L0 22 L12 8" fill="none" stroke="#ffd400" stroke-width="3"/>
      </g>
    </svg>`;
  }

  function renderApModes(g3x) {
    const modes = [];
    const ap = (g3x.ap_state || '').trim();
    const lat = (g3x.fd_lat_mode || '').trim();
    const vert = (g3x.fd_vert_mode || '').trim();
    if (ap) modes.push({ label: 'AP', active: true });
    if (lat) modes.push({ label: lat.toUpperCase(), active: /hdg|nav|apr|track/i.test(lat) });
    if (vert) modes.push({ label: vert.toUpperCase(), active: /alt|vs|glc|vnav/i.test(vert) });
    if (!modes.length) return '';
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
      <div class="g3x-ap-bar" id="g3xAp"></div>
      <div class="g3x-ias-col" id="g3xIas"></div>
      <div class="g3x-sv-col" id="g3xSv"><div class="cesium-cockpit" id="cesiumReplay"></div><div class="g3x-attitude-overlay" id="g3xAttitude"></div></div>
      <div class="g3x-alt-col" id="g3xAlt"></div>
      <div class="g3x-bottom-bar">
        <div class="g3x-wind-box" id="g3xWind"></div>
        <div class="g3x-hsi-wrap" id="g3xHsi"></div>
        <div class="g3x-oat-box" id="g3xOat"></div>
      </div>
      <div class="g3x-hsi-bug-left" id="g3xHdgBug"></div>
      <div class="g3x-hsi-bug-right" id="g3xCrsBug"></div>
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
      if (el('g3xIas')) el('g3xIas').innerHTML = renderIasTape(p, sample.ias_kt, sample.estimated_tas_kt, sample.groundspeed_kt);
      if (el('g3xAlt')) el('g3xAlt').innerHTML = renderAltTape(p, sample.baro_altitude_ft ?? sample.estimated_indicated_altitude_ft, sample.altitude_bug_ft, sample.altimeter_setting_inhg, sample.vertical_speed_fpm ?? sample.estimated_vertical_speed_fpm);
      if (el('g3xAttitude')) el('g3xAttitude').innerHTML = renderAttitudeSvg(sample.pitch_deg, sample.bank_deg, sample.estimated_slip_skid_g);
      if (el('g3xHsi')) el('g3xHsi').innerHTML = renderHsi(sample.heading_deg, sample.heading_bug_deg, g.nav_course_deg, g.nav_source);
      if (el('g3xHdgBug')) el('g3xHdgBug').innerHTML = sample.heading_bug_deg !== null && sample.heading_bug_deg !== undefined ? `HDG ${Math.round(sample.heading_bug_deg)}°` : '';
      if (el('g3xCrsBug')) el('g3xCrsBug').innerHTML = g.nav_course_deg !== null && g.nav_course_deg !== undefined ? `CRS ${Math.round(g.nav_course_deg)}°` : '';

      const windSpd = sample.estimated_wind_speed_kt ?? g.wind_speed_kt;
      const windDir = sample.estimated_wind_direction_deg_true ?? g.wind_dir_deg;
      if (el('g3xWind')) {
        el('g3xWind').innerHTML = Number.isFinite(Number(windSpd)) && Number(windSpd) > 0
          ? `${Math.round(windDir ?? 0)}° / ${fmtNum(windSpd, 0)} KT`
          : 'NO WIND DATA';
      }
      if (el('g3xOat')) el('g3xOat').innerHTML = Number.isFinite(Number(sample.oat_c)) ? `OAT ${fmtNum(sample.oat_c, 0)}°C` : '';
    },
    scale: scalePfd,
  };

  window.CockpitPfd = CockpitPfd;
})();
