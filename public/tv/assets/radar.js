(function (global) {
  'use strict';

  /**
   * KTRM airport geometry — source: OurAirports runways.csv (2026).
   * Taxiways/aprons are approximate layout guides — CALIBRATE against satellite/ADS-B.
   */
  var KTRM_AIRPORT_GEO = {
    center: { lat: 33.62670135498, lon: -116.16000366211 },
    elevFt: 115,
    rangeNm: 2.5,
    diagramRangeNm: 0.88,
    weatherStation: { lat: 33.636485, lon: -116.160931, label: 'ASOS' },
    runways: [
      {
        id: '17/35',
        widthFt: 150,
        ends: [
          { id: '17', lat: 33.639149, lon: -116.156421, heading_degT: 180 },
          { id: '35', lat: 33.615787, lon: -116.156432, heading_degT: 360 }
        ]
      },
      {
        id: '12/30',
        widthFt: 100,
        ends: [
          { id: '12', lat: 33.630174, lon: -116.170989, heading_degT: 135 },
          { id: '30', lat: 33.62046, lon: -116.159381, heading_degT: 315 }
        ]
      }
    ],
    taxiways: [
      { id: 'TWY-A', points: [
        { lat: 33.63862, lon: -116.15462 },
        { lat: 33.63648, lon: -116.15470 },
        { lat: 33.63310, lon: -116.15478 },
        { lat: 33.62970, lon: -116.15486 },
        { lat: 33.62620, lon: -116.15494 },
        { lat: 33.62280, lon: -116.15502 },
        { lat: 33.61940, lon: -116.15510 },
        { lat: 33.61660, lon: -116.15516 }
      ]},
      { id: 'TWY-B', points: [
        { lat: 33.63890, lon: -116.15820 },
        { lat: 33.63870, lon: -116.15490 },
        { lat: 33.63840, lon: -116.15210 }
      ]},
      { id: 'TWY-C', points: [
        { lat: 33.63340, lon: -116.16840 },
        { lat: 33.63180, lon: -116.16320 },
        { lat: 33.63090, lon: -116.15880 },
        { lat: 33.62980, lon: -116.15500 }
      ]},
      { id: 'TWY-D', points: [
        { lat: 33.62720, lon: -116.16780 },
        { lat: 33.62580, lon: -116.16240 },
        { lat: 33.62490, lon: -116.15820 },
        { lat: 33.62380, lon: -116.15510 }
      ]},
      { id: 'TWY-RAMP', points: [
        { lat: 33.63790, lon: -116.16210 },
        { lat: 33.63690, lon: -116.16090 },
        { lat: 33.63580, lon: -116.15970 },
        { lat: 33.63460, lon: -116.15840 }
      ]}
    ],
    aprons: [
      { id: 'SPC-RAMP', points: [
        { lat: 33.63760, lon: -116.16340 },
        { lat: 33.63780, lon: -116.15860 },
        { lat: 33.63520, lon: -116.15820 },
        { lat: 33.63480, lon: -116.16300 }
      ]},
      { id: 'MAIN-RAMP', points: [
        { lat: 33.63120, lon: -116.15720 },
        { lat: 33.63140, lon: -116.15440 },
        { lat: 33.62880, lon: -116.15420 },
        { lat: 33.62860, lon: -116.15700 }
      ]}
    ]
  };

  var NM_PER_DEG_LAT = 60.0;
  var DEG_TO_RAD = Math.PI / 180;
  var RAD_TO_DEG = 180 / Math.PI;
  var SWEEP_PERIOD_MS = 4200;
  var BLIP_TRAIL_TURNS = 8;
  var BLIP_LIFE_MS = SWEEP_PERIOD_MS * BLIP_TRAIL_TURNS;
  var BLIP_SPAWN_MIN_PX = 2.5;
  var WIND_HISTORY_MS = 10 * 60 * 1000;
  var NOISE_BLOB_COUNT = 28;

  function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
  }

  function nmPerDegLon(lat) {
    return NM_PER_DEG_LAT * Math.cos(lat * DEG_TO_RAD);
  }

  function latLonToRadarXY(lat, lon, center, rangeNm, size) {
    var dLat = lat - center.lat;
    var dLon = lon - center.lon;
    var xNm = dLon * nmPerDegLon(center.lat);
    var yNm = dLat * NM_PER_DEG_LAT;
    var half = size / 2;
    var scale = half / rangeNm;
    return {
      x: half + xNm * scale,
      y: half - yNm * scale,
      xNm: xNm,
      yNm: yNm,
      distNm: Math.sqrt(xNm * xNm + yNm * yNm)
    };
  }

  function bearingFromCenter(xNm, yNm) {
    var deg = Math.atan2(xNm, yNm) * RAD_TO_DEG;
    return (deg + 360) % 360;
  }

  function angleDiff(a, b) {
    var d = Math.abs(a - b) % 360;
    return d > 180 ? 360 - d : d;
  }

  function lerpAngle(current, target, t) {
    var delta = ((target - current + 540) % 360) - 180;
    return (current + delta * t + 360) % 360;
  }

  function formatWind(wind) {
    if (!wind || wind.wind_kt == null) return '--- / -- KT';
    var dirVal = wind.wind_dir_raw_deg != null ? wind.wind_dir_raw_deg : wind.wind_dir_deg;
    if (dirVal == null) return '--- / -- KT';
    var dir = String(Math.round(dirVal)).padStart(3, '0');
    var spd = Math.round(wind.wind_kt);
    var gust = wind.gust_kt != null && wind.gust_kt > 0 ? ' G' + Math.round(wind.gust_kt) : '';
    return dir + '° / ' + spd + ' KT' + gust;
  }

  function formatUpdatedAt(weather) {
    if (!weather) return 'Updated —';
    if (weather.recorded_at_local) return 'Updated ' + weather.recorded_at_local;
    return formatUpdatedAtLegacy(weather.updated_at);
  }

  function formatUpdatedAtLegacy(value) {
    if (!value) return 'Updated —';
    var dt = new Date(value);
    if (isNaN(dt.getTime())) return 'Updated —';
    return 'Updated ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  }

  function targetKey(target) {
    if (!target) return '';
    if (target.hex) return String(target.hex).toLowerCase();
    if (target.label) return String(target.label).toUpperCase();
    return String(target.lat) + ',' + String(target.lon);
  }

  function gsToNmPerSec(gsKt) {
    if (gsKt == null || !isFinite(gsKt)) return 0;
    return Math.max(0, gsKt) / 3600;
  }

  function velocityFromTrack(gsKt, trackDeg) {
    var spd = gsToNmPerSec(gsKt);
    if (spd <= 0 || trackDeg == null || !isFinite(trackDeg)) {
      return { vxNm: 0, vyNm: 0 };
    }
    var rad = trackDeg * DEG_TO_RAD;
    return {
      vxNm: spd * Math.sin(rad),
      vyNm: spd * Math.cos(rad)
    };
  }

  function scatterOffset(seed, amount) {
    if (!amount) return { x: 0, y: 0 };
    var a = Math.sin(seed * 12.9898) * 43758.5453;
    var b = Math.sin((seed + 1.7) * 78.233) * 12345.6789;
    var u = a - Math.floor(a);
    var v = b - Math.floor(b);
    return {
      x: (u - 0.5) * amount * 2,
      y: (v - 0.5) * amount * 2
    };
  }

  function RadarScreen(options) {
    this.apiUrl = options.apiUrl || '/tv/api/radar.php';
    this.pollMs = options.pollMs || 15000;
    this.geo = options.geo || KTRM_AIRPORT_GEO;
    this.container = null;
    this.root = null;
    this.scopeCanvas = null;
    this.scopeCtx = null;
    this.diagramCanvas = null;
    this.diagramCtx = null;
    this.statusEl = null;
    this.weatherEls = {};
    this.targets = [];
    this.weather = null;
    this.adsbOk = false;
    this.adsbError = null;
    this.trackStates = {};
    this.noiseBlobs = [];
    this.sweepAngle = 0;
    this.sweepRev = 0;
    this.lastFrame = 0;
    this.animId = null;
    this.pollTimer = null;
    this.resizeObserver = null;
    this.scopeSize = 480;
    this.adsbMeta = {};
    this.windTargetDir = null;
    this.displayWindDir = null;
    this.windHistory = [];
    this.fetching = false;
  }

  RadarScreen.prototype.mount = function (container) {
    this.container = container;
    this.root = document.createElement('div');
    this.root.className = 'tv-radar-screen';
    this.root.innerHTML = [
      '<div class="tv-radar-scope-wrap">',
      '  <canvas class="tv-radar-scope-canvas" aria-label="Radar scope"></canvas>',
      '  <div class="tv-radar-scope-overlay">',
      '    <div class="tv-radar-scope-status" data-radar-adsb-status>INITIALIZING ADS-B</div>',
      '    <div class="tv-radar-scope-footer">',
      '      <div class="tv-radar-scope-chip">RANGE: ' + this.geo.rangeNm + ' NM</div>',
      '      <div class="tv-radar-scope-chip">TILT: 0.5°</div>',
      '    </div>',
      '  </div>',
      '</div>',
      '<div class="tv-radar-side">',
      '  <section class="tv-radar-panel">',
      '    <div class="tv-radar-panel-head"><span>AIRPORT DIAGRAM</span><span class="tv-radar-panel-updated">KTRM</span></div>',
      '    <div class="tv-radar-diagram-body"><canvas class="tv-radar-diagram-canvas" aria-label="Airport diagram"></canvas></div>',
      '  </section>',
      '  <section class="tv-radar-panel">',
      '    <div class="tv-radar-panel-head"><span>WEATHER / ASOS</span><span class="tv-radar-panel-updated" data-radar-weather-updated>Updated —</span></div>',
      '    <div class="tv-radar-weather-body" data-radar-weather-body>',
      '      <div class="tv-radar-wind-block">',
      '        <div class="tv-radar-wind-compass" data-radar-wind-compass>',
      '          <svg class="tv-radar-wind-svg" viewBox="0 0 100 100" aria-hidden="true">',
      '            <circle cx="50" cy="50" r="46" fill="none" stroke="rgba(57,255,106,.22)" stroke-width="1"/>',
      '            <g data-radar-wind-variation></g>',
      '            <g data-radar-wind-fcst opacity="0.85">',
      '              <line x1="50" y1="50" x2="50" y2="16" stroke="rgba(120,210,255,.8)" stroke-width="2.5" stroke-linecap="round"/>',
      '              <polygon points="50,10 46.5,18 53.5,18" fill="rgba(120,210,255,.9)"/>',
      '            </g>',
      '            <g data-radar-wind-live>',
      '              <line x1="50" y1="50" x2="50" y2="12" stroke="#39ff6a" stroke-width="3" stroke-linecap="round"/>',
      '              <polygon points="50,6 45,14 55,14" fill="#39ff6a"/>',
      '            </g>',
      '          </svg>',
      '          <span class="tv-radar-wind-n">N</span>',
      '        </div>',
      '        <div class="tv-radar-wind-value" data-radar-wind-value>--- / -- KT</div>',
      '        <div class="tv-radar-wind-sub" data-radar-runway-favor></div>',
      '      </div>',
      '      <div class="tv-radar-asos-list" data-radar-asos-list></div>',
      '    </div>',
      '  </section>',
      '</div>'
    ].join('');

    container.appendChild(this.root);
    this.scopeCanvas = this.root.querySelector('.tv-radar-scope-canvas');
    this.scopeCtx = this.scopeCanvas.getContext('2d');
    this.diagramCanvas = this.root.querySelector('.tv-radar-diagram-canvas');
    this.diagramCtx = this.diagramCanvas.getContext('2d');
    this.statusEl = this.root.querySelector('[data-radar-adsb-status]');
    this.weatherEls = {
      updated: this.root.querySelector('[data-radar-weather-updated]'),
      body: this.root.querySelector('[data-radar-weather-body]'),
      windValue: this.root.querySelector('[data-radar-wind-value]'),
      windVariation: this.root.querySelector('[data-radar-wind-variation]'),
      windLive: this.root.querySelector('[data-radar-wind-live]'),
      windFcst: this.root.querySelector('[data-radar-wind-fcst]'),
      asosList: this.root.querySelector('[data-radar-asos-list]'),
      runwayFavor: this.root.querySelector('[data-radar-runway-favor]')
    };

    this.seedNoiseBlobs();
    this.bindResize();
    this.resize();
    return this;
  };

  RadarScreen.prototype.seedNoiseBlobs = function () {
    var self = this;
    this.noiseBlobs = [];
    for (var i = 0; i < NOISE_BLOB_COUNT; i += 1) {
      var angle = Math.random() * Math.PI * 2;
      var dist = Math.random() * 0.92;
      this.noiseBlobs.push({
        xNm: Math.sin(angle) * dist * this.geo.rangeNm,
        yNm: Math.cos(angle) * dist * this.geo.rangeNm,
        radius: 0.04 + Math.random() * 0.12,
        phase: Math.random() * Math.PI * 2
      });
    }
  };

  RadarScreen.prototype.bindResize = function () {
    var self = this;
    var onResize = function () {
      window.requestAnimationFrame(function () {
        self.resize();
      });
    };
    window.addEventListener('resize', onResize);
    if (typeof ResizeObserver !== 'undefined' && this.root) {
      this.resizeObserver = new ResizeObserver(onResize);
      var scopeWrap = this.root.querySelector('.tv-radar-scope-wrap');
      if (scopeWrap) {
        this.resizeObserver.observe(scopeWrap);
      }
    }
  };

  RadarScreen.prototype.resize = function () {
    if (!this.scopeCanvas || !this.diagramCanvas || !this.root) return;
    var rootRect = this.root.getBoundingClientRect();
    if (rootRect.width < 10 || rootRect.height < 10) return;

    var scopeWrap = this.scopeCanvas.parentElement;
    var wrapRect = scopeWrap.getBoundingClientRect();
    var size = Math.floor(Math.min(wrapRect.width, wrapRect.height));
    size = Math.max(180, Math.min(size, Math.floor(rootRect.height - 8)));
    var self = this;
    if (size === this.scopeSize && this.diagramCanvas.width > 0) return;

    if (size !== this.scopeSize) {
      Object.keys(this.trackStates).forEach(function (key) {
        self.trackStates[key].blips = [];
      });
    }

    this.scopeSize = size;
    this.scopeCanvas.width = size;
    this.scopeCanvas.height = size;
    this.scopeCanvas.style.width = size + 'px';
    this.scopeCanvas.style.height = size + 'px';

    var diagramBody = this.diagramCanvas.parentElement;
    var dRect = diagramBody.getBoundingClientRect();
    var dWidth = Math.max(120, Math.floor(dRect.width));
    var dHeight = Math.max(90, Math.floor(dRect.height));
    this.diagramCanvas.width = dWidth;
    this.diagramCanvas.height = dHeight;
    this.drawDiagram();
  };

  RadarScreen.prototype.start = function () {
    var self = this;
    this.poll();
    this.pollTimer = window.setInterval(function () {
      self.poll();
    }, this.pollMs);
    this.lastFrame = performance.now();
    var frame = function (ts) {
      self.animate(ts);
      self.animId = requestAnimationFrame(frame);
    };
    this.animId = requestAnimationFrame(frame);
  };

  RadarScreen.prototype.stop = function () {
    if (this.pollTimer) window.clearInterval(this.pollTimer);
    if (this.animId) cancelAnimationFrame(this.animId);
    if (this.resizeObserver) this.resizeObserver.disconnect();
  };

  RadarScreen.prototype.poll = function () {
    var self = this;
    if (this.fetching) return;
    this.fetching = true;
    fetch(this.apiUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) throw new Error('Radar API unavailable');
        return res.json();
      })
      .then(function (payload) {
        self.applyPayload(payload || {});
      })
      .catch(function () {
        self.adsbOk = false;
        self.adsbError = 'ADS-B unavailable';
        self.updateStatus();
        self.weather = { ok: false, error: 'Weather station unavailable' };
        self.updateWeather();
      })
      .finally(function () {
        self.fetching = false;
      });
  };

  RadarScreen.prototype.applyPayload = function (payload) {
    var now = performance.now();
    this.syncTrackStates(Array.isArray(payload.targets) ? payload.targets : [], now);
    this.targets = Array.isArray(payload.targets) ? payload.targets : [];
    this.weather = payload.weather || null;
    this.adsbOk = !!(payload.adsb && payload.adsb.ok);
    this.adsbError = payload.adsb && payload.adsb.error ? payload.adsb.error : null;
    if (payload.center && payload.center.lat != null) {
      this.geo.center.lat = payload.center.lat;
      this.geo.center.lon = payload.center.lon;
    }
    if (payload.range_nm) this.geo.rangeNm = payload.range_nm;
    this.adsbMeta = payload.adsb || {};
    this.updateStatus();
    this.updateWeather();
    this.drawDiagram();
  };

  RadarScreen.prototype.updateStatus = function () {
    if (!this.statusEl) return;
    var meta = this.adsbMeta || {};
    this.statusEl.classList.remove('is-live', 'is-error', 'is-warn');
    if (!this.adsbOk) {
      this.statusEl.textContent = 'ADS-B UNAVAILABLE';
      this.statusEl.classList.add('is-error');
      return;
    }

    var fleet = meta.fleet_count != null ? meta.fleet_count : 0;
    var area = meta.area_count != null ? meta.area_count : 0;
    var areaConnected = !!meta.area_connected;
    var areaRaw = meta.area_raw_count != null ? meta.area_raw_count : 0;

    if (!this.targets.length) {
      if (areaConnected) {
        this.statusEl.textContent = 'ADS-B CONNECTED — NO TRAFFIC IN ' + this.geo.rangeNm + ' NM (AREA ' + areaRaw + ' RAW)';
        this.statusEl.classList.add('is-live');
      } else {
        var err = meta.area_error ? ' — ' + meta.area_error : '';
        this.statusEl.textContent = 'ADS-B PARTIAL — AREA FEED OFFLINE' + err;
        this.statusEl.classList.add('is-warn');
      }
      return;
    }

    var parts = 'ADS-B LIVE — ' + this.targets.length + ' TARGET' + (this.targets.length === 1 ? '' : 'S');
    if (fleet > 0 || area > 0) {
      parts += ' (FLEET ' + fleet + ' / AREA ' + area + ')';
    }
    if (!areaConnected) {
      parts += ' — AREA OFFLINE';
      this.statusEl.classList.add('is-warn');
    } else {
      this.statusEl.classList.add('is-live');
    }
    this.statusEl.textContent = parts;
  };

  RadarScreen.prototype.updateWeather = function () {
    var w = this.weather;
    var els = this.weatherEls;
    if (!els.body) return;

    if (!w || !w.ok) {
      els.updated.textContent = 'Updated —';
      els.body.innerHTML = '<div class="tv-radar-unavailable">WEATHER STATION UNAVAILABLE</div>';
      return;
    }

    els.updated.textContent = formatUpdatedAt(w);
    els.windValue.textContent = formatWind(w);

    var windDir = w.wind_dir_raw_deg != null ? w.wind_dir_raw_deg : w.wind_dir_deg;
    if (windDir != null) {
      this.windTargetDir = windDir;
      if (this.displayWindDir == null) this.displayWindDir = windDir;
      var now = Date.now();
      var last = this.windHistory.length ? this.windHistory[this.windHistory.length - 1] : null;
      if (!last || Math.abs(((windDir - last.dir + 540) % 360) - 180) > 1.5 || now - last.ts > 45000) {
        this.windHistory.push({ dir: windDir, ts: now });
      }
      this.windHistory = this.windHistory.filter(function (entry) {
        return now - entry.ts <= WIND_HISTORY_MS;
      });
      this.renderWindVariation();
    }

    if (els.windFcst) {
      if (w.forecast_wind_dir_deg != null) {
        els.windFcst.setAttribute('transform', 'rotate(' + Math.round(w.forecast_wind_dir_deg) + ' 50 50)');
        els.windFcst.style.opacity = '0.9';
      } else {
        els.windFcst.style.opacity = '0';
      }
    }

    var rows = [
      ['Visibility', w.visibility_sm != null ? w.visibility_sm + ' SM' : '—'],
      ['Sky', w.sky || '—'],
      ['Temperature', w.temp_c != null ? Math.round(w.temp_c) + '°C' : '—'],
      ['Dewpoint', w.dewpoint_c != null ? Math.round(w.dewpoint_c) + '°C' : '—'],
      ['Altimeter', w.altimeter_inhg != null ? w.altimeter_inhg.toFixed(2) + ' inHg' : '—'],
      ['Humidity', w.humidity_pct != null ? w.humidity_pct + '%' : '—'],
      ['Density Alt', w.density_alt_ft != null ? w.density_alt_ft + ' FT' : '—'],
      ['Precipitation', w.precipitation || '—']
    ];

    els.asosList.innerHTML = rows.map(function (row) {
      return '<div class="tv-radar-asos-row"><span class="tv-radar-asos-label">' + row[0] + '</span><span class="tv-radar-asos-value">' + row[1] + '</span></div>';
    }).join('');

    if (els.runwayFavor && Array.isArray(w.runway_components) && w.runway_components.length) {
      var best = w.runway_components[0];
      els.runwayFavor.textContent = 'RWY ' + best.id + ' — XW ' + best.crosswind_kt + ' KT / HW ' + best.headwind_kt + ' KT';
    } else {
      els.runwayFavor.textContent = '';
    }
  };

  RadarScreen.prototype.renderWindVariation = function () {
    var g = this.weatherEls.windVariation;
    if (!g) return;
    var now = Date.now();
    var ticks = this.windHistory.map(function (entry) {
      var age = (now - entry.ts) / WIND_HISTORY_MS;
      var alpha = 0.25 + (1 - Math.min(1, age)) * 0.65;
      var rad = entry.dir * DEG_TO_RAD;
      var cx = 50;
      var cy = 50;
      var rOuter = 46;
      var rInner = 39;
      var x1 = cx + Math.sin(rad) * rOuter;
      var y1 = cy - Math.cos(rad) * rOuter;
      var x2 = cx + Math.sin(rad) * rInner;
      var y2 = cy - Math.cos(rad) * rInner;
      return '<line x1="' + x1.toFixed(2) + '" y1="' + y1.toFixed(2) + '" x2="' + x2.toFixed(2) + '" y2="' + y2.toFixed(2) + '" stroke="rgba(57,255,106,' + alpha.toFixed(2) + ')" stroke-width="2" stroke-linecap="round"/>';
    });
    g.innerHTML = ticks.join('');
  };

  RadarScreen.prototype.updateWindAnimation = function (ts, dtSec) {
    if (this.windTargetDir == null || !this.weatherEls.windLive) return;
    if (this.displayWindDir == null) this.displayWindDir = this.windTargetDir;
    var wave = Math.sin(ts / 2800) * 2.4 + Math.sin(ts / 5300) * 1.2;
    var desired = this.windTargetDir + wave;
    this.displayWindDir = lerpAngle(this.displayWindDir, desired, Math.min(1, dtSec * 2.6));
    this.weatherEls.windLive.setAttribute('transform', 'rotate(' + this.displayWindDir.toFixed(1) + ' 50 50)');
  };

  RadarScreen.prototype.syncTrackStates = function (targets, ts) {
    var self = this;
    var seen = {};

    (targets || []).forEach(function (target) {
      if (target.lat == null || target.lon == null) return;
      var key = targetKey(target);
      if (!key) return;
      seen[key] = true;

      var xy = latLonToRadarXY(target.lat, target.lon, self.geo.center, self.geo.rangeNm, self.scopeSize);
      var state = self.trackStates[key];
      var gsVel = velocityFromTrack(target.gs, target.track_deg);

      if (!state) {
        var initHeading = target.track_deg != null ? target.track_deg : 0;
        state = {
          key: key,
          target: target,
          fixXNm: xy.xNm,
          fixYNm: xy.yNm,
          displayXNm: xy.xNm,
          displayYNm: xy.yNm,
          vxNm: gsVel.vxNm,
          vyNm: gsVel.vyNm,
          displayHeading: initHeading,
          lastFixTs: ts,
          blips: [],
          lastBlipX: null,
          lastBlipY: null,
          lastBlipSpawnTs: 0,
          scatterSeed: Math.random() * 1000
        };
        self.trackStates[key] = state;
        return;
      }

      var dtSec = Math.max(0.05, (ts - state.lastFixTs) / 1000);
      var measVx = (xy.xNm - state.fixXNm) / dtSec;
      var measVy = (xy.yNm - state.fixYNm) / dtSec;

      state.target = target;
      state.fixXNm = xy.xNm;
      state.fixYNm = xy.yNm;
      state.lastFixTs = ts;

      if (target.gs != null && target.gs > 2) {
        state.vxNm = measVx * 0.25 + gsVel.vxNm * 0.75;
        state.vyNm = measVy * 0.25 + gsVel.vyNm * 0.75;
      } else if (target.gs != null && target.gs > 0.5) {
        state.vxNm = measVx * 0.4 + gsVel.vxNm * 0.6;
        state.vyNm = measVy * 0.4 + gsVel.vyNm * 0.6;
      } else {
        state.vxNm = measVx * 0.5;
        state.vyNm = measVy * 0.5;
      }

      var gs = target.gs != null ? target.gs : 0;
      if (gs > 1 && target.track_deg != null) {
        state.displayHeading = lerpAngle(state.displayHeading, target.track_deg, 0.35);
      } else if (Math.hypot(state.vxNm, state.vyNm) > 0.00005) {
        var velHeading = Math.atan2(state.vxNm, state.vyNm) * RAD_TO_DEG;
        if (velHeading < 0) velHeading += 360;
        state.displayHeading = lerpAngle(state.displayHeading, velHeading, 0.25);
      }

      var err = Math.hypot(xy.xNm - state.displayXNm, xy.yNm - state.displayYNm);
      if (err > 0.5 && gs < 4) {
        state.displayXNm = xy.xNm;
        state.displayYNm = xy.yNm;
      }
    });

    Object.keys(this.trackStates).forEach(function (key) {
      if (!seen[key]) delete self.trackStates[key];
    });
  };

  RadarScreen.prototype.advanceTrackStates = function (dtSec) {
    Object.keys(this.trackStates).forEach(function (key) {
      var state = this.trackStates[key];
      var target = state.target || {};
      var gs = target.gs != null ? target.gs : 0;
      state.displayXNm += state.vxNm * dtSec;
      state.displayYNm += state.vyNm * dtSec;

      var pullRate = gs > 8 ? 0.45 : (gs > 3 ? 0.9 : (gs > 0.5 ? 1.4 : 2.2));
      var pull = Math.min(1, dtSec * pullRate);
      state.displayXNm += (state.fixXNm - state.displayXNm) * pull;
      state.displayYNm += (state.fixYNm - state.displayYNm) * pull;
    }, this);
  };

  RadarScreen.prototype.displayXYForState = function (state) {
    var half = this.scopeSize / 2;
    var scale = half / this.geo.rangeNm;
    return {
      x: half + state.displayXNm * scale,
      y: half - state.displayYNm * scale,
      xNm: state.displayXNm,
      yNm: state.displayYNm
    };
  };

  RadarScreen.prototype.animate = function (ts) {
    var dt = Math.min(80, Math.max(0, ts - this.lastFrame));
    this.lastFrame = ts;
    var dtSec = dt / 1000;
    this.sweepAngle = (this.sweepAngle + (dt / SWEEP_PERIOD_MS) * 360) % 360;
    this.sweepRev = Math.floor(ts / SWEEP_PERIOD_MS);
    this.advanceTrackStates(dtSec);
    this.updateContinuousBlips(ts);
    this.updateWindAnimation(ts, dtSec);
    this.drawScope(ts);
  };

  RadarScreen.prototype.updateContinuousBlips = function (ts) {
    var self = this;
    Object.keys(this.trackStates).forEach(function (key) {
      var state = self.trackStates[key];
      var target = state.target || {};
      var xy = self.displayXYForState(state);
      if (!state.blips) state.blips = [];

      var moved = state.lastBlipX == null ? 999 : Math.hypot(xy.x - state.lastBlipX, xy.y - state.lastBlipY);
      var gs = target.gs != null ? target.gs : 0;
      var spawnMs = gs > 12 ? 180 : (gs > 6 ? 260 : (gs > 2 ? 420 : (gs > 0.5 ? 700 : 1600)));
      var elapsed = ts - (state.lastBlipSpawnTs || 0);

      if (moved >= BLIP_SPAWN_MIN_PX || elapsed >= spawnMs) {
        state.blips.push({ x: xy.x, y: xy.y, born: ts });
        state.lastBlipX = xy.x;
        state.lastBlipY = xy.y;
        state.lastBlipSpawnTs = ts;
      }

      state.blips = state.blips.filter(function (blip) {
        return ts - blip.born < BLIP_LIFE_MS;
      });
      if (state.blips.length > 48) {
        state.blips = state.blips.slice(state.blips.length - 48);
      }
    });
  };

  RadarScreen.prototype.drawScope = function (ts) {
    var ctx = this.scopeCtx;
    var size = this.scopeSize;
    var center = size / 2;
    if (!ctx) return;

    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#06101c';
    ctx.fillRect(0, 0, size, size);

    this.drawAirportLayer(ctx, size, 0.16, true);
    this.drawRangeRings(ctx, size);
    this.drawNoiseReturns(ctx, ts);
    this.drawSweep(ctx, size);
    this.drawPrimaryBlips(ctx, ts);
    this.drawSSRTargets(ctx);
  };

  RadarScreen.prototype.drawRangeRings = function (ctx, size) {
    var center = size / 2;
    var half = center;
    var range = this.geo.rangeNm;
    var step = range <= 3 ? 0.5 : 1;
    ctx.save();
    ctx.translate(center, center);
    for (var nm = step; nm <= range + 0.001; nm += step) {
      var r = (nm / range) * half;
      var isOuter = Math.abs(nm - range) < 0.001;
      ctx.beginPath();
      ctx.arc(0, 0, r, 0, Math.PI * 2);
      ctx.strokeStyle = isOuter ? 'rgba(57,255,106,.42)' : 'rgba(57,255,106,.18)';
      ctx.lineWidth = isOuter ? 1.4 : 1;
      ctx.stroke();
      ctx.fillStyle = 'rgba(57,255,106,.55)';
      ctx.font = '10px monospace';
      ctx.textAlign = 'center';
      ctx.fillText(nm % 1 === 0 ? String(nm) : nm.toFixed(1), 0, -r + 12);
    }
    this.drawCompassRose(ctx, half);
    ctx.restore();
  };

  RadarScreen.prototype.drawCompassRose = function (ctx, radius) {
    var labels = [
      { t: 'N', a: 0 }, { t: 'E', a: 90 }, { t: 'S', a: 180 }, { t: 'W', a: 270 }
    ];
    labels.forEach(function (item) {
      var rad = item.a * DEG_TO_RAD;
      var x = Math.sin(rad) * (radius - 10);
      var y = -Math.cos(rad) * (radius - 10);
      ctx.fillStyle = 'rgba(57,255,106,.75)';
      ctx.font = 'bold 11px monospace';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(item.t, x, y);
    });
  };

  RadarScreen.prototype.drawAirportLayer = function (ctx, size, alpha, scopeMode, layerOpts) {
    var self = this;
    var geo = this.geo;
    layerOpts = layerOpts || {};
    var rangeNm = layerOpts.rangeNm != null ? layerOpts.rangeNm : geo.rangeNm;
    var favoredRunway = layerOpts.favoredRunway || null;

    var toXY = function (lat, lon) {
      return latLonToRadarXY(lat, lon, geo.center, rangeNm, size);
    };

    var drawPolyline = function (points, color, width) {
      if (!points || points.length < 2) return;
      ctx.beginPath();
      points.forEach(function (pt, idx) {
        var xy = toXY(pt.lat, pt.lon);
        if (idx === 0) ctx.moveTo(xy.x, xy.y);
        else ctx.lineTo(xy.x, xy.y);
      });
      ctx.strokeStyle = color;
      ctx.lineWidth = width;
      ctx.stroke();
    };
    var drawPolygon = function (points, fill, stroke) {
      if (!points || points.length < 3) return;
      ctx.beginPath();
      points.forEach(function (pt, idx) {
        var xy = toXY(pt.lat, pt.lon);
        if (idx === 0) ctx.moveTo(xy.x, xy.y);
        else ctx.lineTo(xy.x, xy.y);
      });
      ctx.closePath();
      if (fill) {
        ctx.fillStyle = fill;
        ctx.fill();
      }
      if (stroke) {
        ctx.strokeStyle = stroke;
        ctx.lineWidth = 1;
        ctx.stroke();
      }
    };

    (geo.aprons || []).forEach(function (apron) {
      drawPolygon(apron.points, 'rgba(140,155,170,' + (alpha * 0.55) + ')', 'rgba(140,155,170,' + (alpha * 0.8) + ')');
    });

    (geo.taxiways || []).forEach(function (taxi) {
      drawPolyline(taxi.points, 'rgba(140,155,170,' + (alpha * 0.95) + ')', scopeMode ? 1.2 : 1.6);
    });

    (geo.runways || []).forEach(function (runway) {
      if (!runway.ends || runway.ends.length < 2) return;
      var favored = favoredRunway && runway.ends.some(function (end) {
        return String(end.id) === String(favoredRunway);
      });
      var runwayColor = favored
        ? 'rgba(57,255,106,' + Math.min(1, alpha + 0.55) + ')'
        : 'rgba(210,220,230,' + Math.min(1, alpha + 0.25) + ')';
      var runwayWidth = favored ? (scopeMode ? 3.4 : 5.2) : (scopeMode ? 2.4 : 3.2);
      drawPolyline(runway.ends, runwayColor, runwayWidth);
      runway.ends.forEach(function (end) {
        var xy = toXY(end.lat, end.lon);
        if (!scopeMode) {
          var isEndFavored = favoredRunway && String(end.id) === String(favoredRunway);
          ctx.fillStyle = isEndFavored ? '#39ff6a' : 'rgba(232,242,255,.85)';
          ctx.font = 'bold ' + (isEndFavored ? '13px' : '11px') + ' monospace';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(end.id, xy.x, xy.y);
        }
      });
    });

    if (geo.weatherStation) {
      var ws = toXY(geo.weatherStation.lat, geo.weatherStation.lon);
      ctx.beginPath();
      ctx.arc(ws.x, ws.y, scopeMode ? 3 : 4, 0, Math.PI * 2);
      ctx.fillStyle = scopeMode ? 'rgba(80,180,255,.75)' : '#4cb8ff';
      ctx.fill();
    }
  };

  RadarScreen.prototype.drawNoiseReturns = function (ctx, ts) {
    var self = this;
    var size = this.scopeSize;
    this.noiseBlobs.forEach(function (blob) {
      var x = size / 2 + blob.xNm * (size / 2 / self.geo.rangeNm);
      var y = size / 2 - blob.yNm * (size / 2 / self.geo.rangeNm);
      var pulse = 0.45 + 0.25 * Math.sin(ts / 900 + blob.phase);
      var r = blob.radius * (size / self.geo.rangeNm) * 8 * pulse;
      var grad = ctx.createRadialGradient(x, y, 0, x, y, r);
      grad.addColorStop(0, 'rgba(57,255,106,.18)');
      grad.addColorStop(1, 'rgba(57,255,106,0)');
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.arc(x, y, r, 0, Math.PI * 2);
      ctx.fill();
    });
  };

  RadarScreen.prototype.drawSweep = function (ctx, size) {
    var center = size / 2;
    var radius = center - 2;
    var start = (this.sweepAngle - 28) * DEG_TO_RAD;
    var end = this.sweepAngle * DEG_TO_RAD;
    ctx.save();
    ctx.translate(center, center);
    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.arc(0, 0, radius, start - Math.PI / 2, end - Math.PI / 2);
    ctx.closePath();
    var grad = ctx.createRadialGradient(0, 0, 0, 0, 0, radius);
    grad.addColorStop(0, 'rgba(57,255,106,.05)');
    grad.addColorStop(0.72, 'rgba(57,255,106,.12)');
    grad.addColorStop(1, 'rgba(57,255,106,.28)');
    ctx.fillStyle = grad;
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.lineTo(Math.sin(end) * radius, -Math.cos(end) * radius);
    ctx.strokeStyle = 'rgba(57,255,106,.75)';
    ctx.lineWidth = 1.5;
    ctx.stroke();
    ctx.restore();
  };

  RadarScreen.prototype.drawPrimaryBlips = function (ctx, ts) {
    var self = this;

    Object.keys(this.trackStates).forEach(function (key) {
      var state = self.trackStates[key];
      var blips = state.blips || [];
      if (!blips.length) return;

      blips.forEach(function (blip, idx) {
        var age = ts - blip.born;
        if (age < 0 || age >= BLIP_LIFE_MS) return;
        var lifeT = age / BLIP_LIFE_MS;
        var alpha = 0.96 * Math.pow(1 - lifeT, 1.35);
        if (alpha < 0.02) return;

        var scatterAmt = lifeT * 5.5;
        var scatter = scatterOffset(state.scatterSeed + idx * 2.7 + lifeT * 4, scatterAmt);
        var bx = blip.x + scatter.x;
        var by = blip.y + scatter.y;
        var coreR = 5.2 - lifeT * 2.2;
        var glowR = 7 + lifeT * 10;

        ctx.save();
        if (lifeT > 0.08) {
          ctx.filter = 'blur(' + (0.5 + lifeT * 2.2) + 'px)';
        }

        var grad = ctx.createRadialGradient(bx, by, 0, bx, by, glowR);
        grad.addColorStop(0, 'rgba(210,255,220,' + (alpha * 0.98) + ')');
        grad.addColorStop(0.18, 'rgba(80,255,110,' + (alpha * 0.72) + ')');
        grad.addColorStop(0.45, 'rgba(40,220,80,' + (alpha * 0.32) + ')');
        grad.addColorStop(1, 'rgba(20,120,50,0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(bx, by, glowR, 0, Math.PI * 2);
        ctx.fill();

        if (lifeT < 0.45) {
          ctx.filter = 'none';
          ctx.fillStyle = 'rgba(235,255,240,' + (alpha * 0.9) + ')';
          ctx.beginPath();
          ctx.arc(bx, by, Math.max(1.5, coreR), 0, Math.PI * 2);
          ctx.fill();
        }

        ctx.restore();
      });
    });
  };

  RadarScreen.prototype.drawSSRTargets = function (ctx) {
    var self = this;
    var states = Object.keys(this.trackStates).map(function (key) {
      return self.trackStates[key];
    });

    states.forEach(function (state, idx) {
      var target = state.target;
      if (!target) return;
      var xy = self.displayXYForState(state);
      var heading = state.displayHeading != null ? state.displayHeading : (target.track_deg != null ? target.track_deg : 0);
      if (target.gs != null && target.gs > 3 && Math.hypot(state.vxNm, state.vyNm) > 0.00008) {
        heading = Math.atan2(state.vxNm, state.vyNm) * RAD_TO_DEG;
        if (heading < 0) heading += 360;
        state.displayHeading = lerpAngle(state.displayHeading, heading, 0.18);
        heading = state.displayHeading;
      }
      self.drawAircraftSymbol(ctx, xy.x, xy.y, heading);
      self.drawSSRLabel(ctx, xy.x, xy.y, target, idx);
    });
  };

  RadarScreen.prototype.drawAircraftSymbol = function (ctx, x, y, headingDeg) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(headingDeg * DEG_TO_RAD);
    ctx.fillStyle = '#f4f8ff';
    ctx.strokeStyle = 'rgba(255,255,255,.85)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, -7);
    ctx.lineTo(4.5, 5);
    ctx.lineTo(0, 2.5);
    ctx.lineTo(-4.5, 5);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  };

  RadarScreen.prototype.drawSSRLabel = function (ctx, x, y, target, idx) {
    var side = idx % 2 === 0 ? 1 : -1;
    var labelX = x + 16 * side;
    var labelY = y - 18;
    ctx.strokeStyle = 'rgba(255,255,255,.55)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x + 4 * side, y - 2);
    ctx.lineTo(labelX - 6 * side, labelY + 18);
    ctx.stroke();

    var lines = [
      target.label || target.hex || 'UNKNOWN',
      (target.gs != null ? Math.round(target.gs) + 'KT' : '--KT'),
      (target.dist_nm != null ? target.dist_nm.toFixed(1) + 'NM' : '--NM')
    ];
    if (target.alt_ft != null && !target.on_ground) {
      lines.push(Math.round(target.alt_ft) + 'FT');
    }

    ctx.font = 'bold 11px monospace';
    ctx.textAlign = side > 0 ? 'left' : 'right';
    ctx.textBaseline = 'top';
    lines.forEach(function (line, i) {
      ctx.fillStyle = i === 0 ? '#ffffff' : 'rgba(232,242,255,.82)';
      ctx.fillText(line, labelX, labelY + i * 12);
    });
  };

  RadarScreen.prototype.drawDiagram = function () {
    var ctx = this.diagramCtx;
    var canvas = this.diagramCanvas;
    if (!ctx || !canvas) return;
    var w = canvas.width;
    var h = canvas.height;
    var diagramRange = this.geo.diagramRangeNm || 0.88;
    var favored = this.weather && this.weather.favored_runway ? this.weather.favored_runway : null;
    var drawSize = Math.min(w, h);

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = '#0a1524';
    ctx.fillRect(0, 0, w, h);

    ctx.save();
    ctx.translate((w - drawSize) / 2, (h - drawSize) / 2);
    this.drawAirportLayer(ctx, drawSize, 0.95, false, {
      rangeNm: diagramRange,
      favoredRunway: favored
    });
    ctx.restore();
  };

  global.TvRadarScreen = RadarScreen;
  global.TvRadarGeo = KTRM_AIRPORT_GEO;
  global.latLonToRadarXY = latLonToRadarXY;
})(window);
