/**
 * Master Logbook Edit Operational Leg — GPS track + traffic scrubber.
 * Expects Leaflet (window.L) and a container with the legs-track-* ids.
 */
(function (global) {
  'use strict';

  const PLAY_RATE = 8;
  const TRAIL_SECONDS = 90;
  const HOLD_SECONDS = 45;
  // Live map renders this far behind wall-clock so markers always interpolate
  // between known ADS-B samples instead of freezing until the next poll.
  const LIVE_DISPLAY_DELAY_S = 15;
  // Distinct from yellow small-prop traffic markers.
  const OWNSHIP_COLOR = '#ec4899';
  const RANGE_RINGS_NM = [2.5, 5, 7.5, 10, 15, 20];
  const NM_TO_METERS = 1852;
  const OPENFREEMAP_LIBERTY = 'https://tiles.openfreemap.org/styles/liberty';

  function canUseOpenFreeMap() {
    return typeof maplibregl !== 'undefined'
      && typeof L !== 'undefined'
      && typeof L.maplibreGL === 'function';
  }

  function createBasemapLayer(kind) {
    if (kind === 'adsb' && canUseOpenFreeMap()) {
      return L.maplibreGL({
        style: OPENFREEMAP_LIBERTY,
        interactive: false,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://openfreemap.org">OpenFreeMap</a>'
      });
    }
    if (kind === 'adsb') {
      // Fallback if MapLibre failed to load: standard OSM still beats washed-out Voyager.
      return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
      });
    }
    return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    });
  }

  function addBasemapLayer(map, kind) {
    try {
      return createBasemapLayer(kind).addTo(map);
    } catch (error) {
      // OpenFreeMap is an enhancement, not a dependency of live tracking.
      // If MapLibre/the bridge fails, keep the known-good raster map alive.
      return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
      }).addTo(map);
    }
  }

  function bringLayerGroupToFront(group) {
    if (!group || typeof group.eachLayer !== 'function') return;
    group.eachLayer((layer) => {
      if (layer && typeof layer.bringToFront === 'function') {
        layer.bringToFront();
      }
    });
  }

  function finite(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }

  function formatClock(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    if (h > 0) {
      return String(h) + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function colorFor(id) {
    let hash = 0;
    const text = String(id || 'traffic');
    for (let i = 0; i < text.length; i++) {
      hash = ((hash << 5) - hash) + text.charCodeAt(i);
      hash |= 0;
    }
    const hue = Math.abs(hash) % 360;
    return 'hsl(' + hue + ' 70% 42%)';
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[ch]));
  }

  function aircraftDisplayType(item, sample) {
    const category = String((sample && sample.category) || '').toUpperCase();
    const callsign = String((item && (item.callsign || item.registration || item.hex)) || '').toUpperCase();
    const alt = finite(sample && (sample.altitude_ft ?? sample.alt));
    const gs = finite(sample && (sample.groundspeed_kt ?? sample.gs));
    if (/^(A7|H)/.test(category) || /(HELI|COPTER|LIFE|MEDEVAC|AIRMED|NIGHT|STAR|CHOPPER)/.test(callsign)) {
      return { key: 'helicopter', label: 'Helicopter Traffic', color: '#2f9e5d', size: 32, viewBox: '0 0 48 48' };
    }
    if (/^(RCH|REACH|CNV|JOSA|PAT|VV|VM|NAVY|ARMY|USAF|AF|MARINE|COAST|GUARD|NATO|GAF|RAF|FNF|BAF|CAF|LAGR|TANKR|TORCH|MACE|HAWK|VIPER|RAVEN|EAGLE|DRAGO|SHELL)/.test(callsign)) {
      return { key: 'military', label: 'Possible Military Traffic', color: '#64748b', size: 42, viewBox: '0 0 64 64' };
    }
    if (/^(AAL|DAL|UAL|SWA|ASA|SKW|ENY|FDX|UPS|BAW|DLH|JBU|NKS|FFT|ACA|KLM|AFR|QTR|UAE|SIA)/.test(callsign) || /^(A4|A5|B4|B5|C4|C5)/.test(category) || (gs !== null && gs >= 300)) {
      return { key: 'large-jet', label: 'Large Jet Airplane', color: '#2563eb', size: 42, viewBox: '0 0 64 64' };
    }
    if (/^(A3|A6|B3|B6|C3|C6)/.test(category) || (gs !== null && gs >= 220) || (alt !== null && alt >= 14000)) {
      return { key: 'business-jet', label: 'Business Jet Airplane', color: '#f8fafc', size: 36, viewBox: '0 0 56 56' };
    }
    return { key: 'small-prop', label: 'Small Prop Airplane', color: '#facc15', size: 30, viewBox: '0 0 48 48' };
  }

  function aircraftShape(displayType) {
    switch (displayType.key) {
      case 'large-jet':
        return '<path d="M31 4c4 0 5 8 5 18l21 12v6l-21-5-3 20 8 5v4l-10-3-10 3v-4l8-5-3-20-21 5v-6l21-12C26 12 27 4 31 4z"/><rect x="13" y="31" width="6" height="8" rx="1"/><rect x="45" y="31" width="6" height="8" rx="1"/>';
      case 'business-jet':
        return '<path d="M27 3c4 0 5 8 5 19l18 14v5l-18-6-2 13 7 4v4l-10-3-10 3v-4l7-4-2-13-18 6v-5l18-14C22 11 23 3 27 3z"/><rect x="16" y="34" width="5" height="8" rx="1"/><rect x="35" y="34" width="5" height="8" rx="1"/>';
      case 'helicopter':
        return '<path d="M23 3h2v42h-2z" fill="#111827"/><path d="M3 23h42v2H3z" fill="#111827"/><path d="M13 8 40 35l-5 5L8 13z" fill="#111827"/><path d="M35 8 8 35l5 5L40 13z" fill="#111827"/><ellipse cx="24" cy="25" rx="8" ry="13"/><path d="M21 37h6v7h-6z"/><path d="M20 44h8v3h-8z"/><path d="M17 22h14v11H17z" fill="rgba(15,23,42,.22)"/><circle cx="24" cy="24" r="3" fill="rgba(255,255,255,.22)"/>';
      case 'military':
        return '<path d="M31 3 41 24l19 11-16 7 2 14-15-6-15 6 2-14-16-7 19-11z"/><path d="M31 9 37 28l11 7-11 4 1 8-7-4-7 4 1-8-11-4 11-7z" fill="rgba(15,23,42,.28)"/>';
      default:
        return '<path d="M23 5c3 0 4 6 4 15l16 4v6l-16-1-2 12 6 3v3l-8-2-8 2v-3l6-3-2-12-16 1v-6l16-4C19 11 20 5 23 5z"/>';
    }
  }

  function formatSquawk(value) {
    if (value === null || value === undefined) return null;
    const text = String(value).trim().toUpperCase();
    if (!text) return null;
    // ADS-B squawk is normally 4 octal digits; keep raw if already formatted.
    if (/^\d{1,4}$/.test(text)) return text.padStart(4, '0');
    return text;
  }

  function archiveAircraftLabel(item, sample) {
    const label = String((item && (item.callsign || item.registration || item.hex)) || '').trim().toUpperCase();
    const altitude = finite(sample && (sample.altitude_ft ?? sample.alt));
    const speed = finite(sample && (sample.groundspeed_kt ?? sample.gs));
    const squawk = formatSquawk(sample && (sample.squawk ?? sample.squawk_code))
      || formatSquawk(item && (item.squawk ?? item.squawk_code));
    const speedAltitude = (speed !== null ? Math.round(speed).toLocaleString() + ' kt' : '-- kt')
      + ' '
      + (altitude !== null ? Math.round(altitude).toLocaleString() + ' ft' : '-- ft')
      + (squawk ? (' · SQ ' + squawk) : '');
    return '<strong>' + escapeHtml(speedAltitude) + '</strong><span>' + escapeHtml(label || 'TRAFFIC') + '</span>';
  }

  function archiveAircraftIcon(item, sample, colorOverride) {
    const displayType = aircraftDisplayType(item, sample);
    const heading = finite(sample && (sample.track_deg ?? sample.track ?? sample.heading_deg)) ?? 0;
    const size = displayType.size;
    const stroke = displayType.key === 'business-jet' ? '#0f172a' : '#111827';
    const fill = colorOverride || displayType.color;
    return L.divIcon({
      className: '',
      iconSize: [size, size],
      iconAnchor: [size / 2, size / 2],
      html: '<div class="adsb-aircraft-symbol adsb-aircraft-symbol-' + displayType.key + '">'
        + '<svg class="adsb-aircraft-symbol-plane" viewBox="' + displayType.viewBox + '" aria-hidden="true" style="transform:rotate(' + heading + 'deg)">'
        + '<g fill="' + fill + '" stroke="' + stroke + '" stroke-width="2" stroke-linejoin="round">' + aircraftShape(displayType) + '</g>'
        + '</svg>'
        + '<div class="adsb-aircraft-label">' + archiveAircraftLabel(item, sample) + '</div>'
        + '</div>'
    });
  }

  function airplaneIcon(options) {
    const opts = options || {};
    return archiveAircraftIcon(
      {
        callsign: opts.label || opts.registration || '',
        registration: opts.registration || opts.label || '',
        hex: opts.hex || ''
      },
      {
        track_deg: opts.heading,
        groundspeed_kt: opts.gs,
        altitude_ft: opts.alt,
        category: opts.category || ''
      },
      opts.color
    );
  }

  function aircraftIcon(label, color, isOwnship) {
    return airplaneIcon({
      label: label,
      color: color || (isOwnship ? OWNSHIP_COLOR : undefined),
      heading: 0
    });
  }

  function bearingBetweenDeg(lat1, lon1, lat2, lon2) {
    const toRad = Math.PI / 180;
    const φ1 = lat1 * toRad;
    const φ2 = lat2 * toRad;
    const Δλ = (lon2 - lon1) * toRad;
    const y = Math.sin(Δλ) * Math.cos(φ2);
    const x = Math.cos(φ1) * Math.sin(φ2) - Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ);
    let brng = Math.atan2(y, x) * 180 / Math.PI;
    if (brng < 0) brng += 360;
    return brng;
  }

  function haversineNm(lat1, lon1, lat2, lon2) {
    const toRad = Math.PI / 180;
    const dLat = (lat2 - lat1) * toRad;
    const dLon = (lon2 - lon1) * toRad;
    const a = Math.sin(dLat / 2) ** 2
      + Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLon / 2) ** 2;
    return 3440.065 * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
  }

  function sampleAltFt(sample) {
    return finite(sample && (sample.altBaroFt ?? sample.alt ?? sample.altitude_ft));
  }

  function sampleGsKt(sample) {
    return finite(sample && (sample.groundspeed_kt ?? sample.gs));
  }

  function sampleTrackDeg(sample) {
    return finite(sample && (sample.trackTrueDeg ?? sample.trk ?? sample.heading_deg ?? sample.track_deg ?? sample.track));
  }

  function sampleSquawkCode(sample) {
    return formatSquawk(sample && (sample.squawk ?? sample.squawk_code));
  }

  function nearestByT(samples, t, holdSeconds) {
    const list = Array.isArray(samples) ? samples : [];
    let best = null;
    let bestDelta = Infinity;
    for (let i = 0; i < list.length; i++) {
      const sample = list[i];
      const st = finite(sample && sample.t);
      if (st === null) continue;
      const delta = Math.abs(st - t);
      if (delta < bestDelta) {
        best = sample;
        bestDelta = delta;
      }
    }
    if (!best || bestDelta > (holdSeconds ?? HOLD_SECONDS)) return null;
    return best;
  }

  function interpolateByT(samples, t) {
    const list = (Array.isArray(samples) ? samples : [])
      .filter((sample) => finite(sample && sample.t) !== null
        && finite(sample && sample.lat) !== null
        && finite(sample && sample.lon) !== null)
      .sort((a, b) => Number(a.t) - Number(b.t));
    if (!list.length) return null;

    // Derive missing gs/track so sparse ownship archive never freezes between points.
    for (let i = 0; i < list.length; i++) {
      const cur = list[i];
      if (sampleGsKt(cur) !== null && sampleTrackDeg(cur) !== null) continue;
      const prev = i > 0 ? list[i - 1] : null;
      const next = i + 1 < list.length ? list[i + 1] : null;
      const a = prev || cur;
      const b = next || cur;
      if (a !== b) {
        const dt = Math.max(0.2, Number(b.t) - Number(a.t));
        const distNm = haversineNm(Number(a.lat), Number(a.lon), Number(b.lat), Number(b.lon));
        if (sampleGsKt(cur) === null && distNm > 0.001) {
          cur.groundspeed_kt = (distNm / dt) * 3600;
          cur.gs = cur.groundspeed_kt;
        }
        if (sampleTrackDeg(cur) === null && distNm > 0.001) {
          const brng = bearingBetweenDeg(Number(a.lat), Number(a.lon), Number(b.lat), Number(b.lon));
          cur.track_deg = brng;
          cur.trackTrueDeg = brng;
          cur.heading_deg = brng;
        }
      }
      if (sampleGsKt(cur) === null && prev) {
        cur.groundspeed_kt = sampleGsKt(prev);
        cur.gs = cur.groundspeed_kt;
      }
      if (sampleTrackDeg(cur) === null && prev) {
        const brng = sampleTrackDeg(prev);
        cur.track_deg = brng;
        cur.trackTrueDeg = brng;
        cur.heading_deg = brng;
      }
      if (!cur.squawk && prev && prev.squawk) cur.squawk = prev.squawk;
    }

    let before = null;
    let after = null;
    for (let i = 0; i < list.length; i++) {
      const sample = list[i];
      if (Number(sample.t) <= t) before = sample;
      if (Number(sample.t) >= t) {
        after = sample;
        break;
      }
    }

    function deadReckon(from, dtSec) {
      if (!from) return null;
      const gs = sampleGsKt(from);
      const track = sampleTrackDeg(from);
      if (gs === null || track === null) return null;
      const dt = Math.max(0, Math.min(45, dtSec));
      const alt = sampleAltFt(from);
      if (dt <= 0) {
        return {
          t,
          lat: Number(from.lat),
          lon: Number(from.lon),
          alt: alt,
          gs: gs,
          vs: null,
          track: track,
          squawk: sampleSquawkCode(from),
          callsign: from.cs || from.callsign || null
        };
      }
      const distNm = (gs * dt) / 3600;
      const rad = track * Math.PI / 180;
      const dLat = (distNm / 60) * Math.cos(rad);
      const dLon = (distNm / (60 * Math.max(0.2, Math.cos(Number(from.lat) * Math.PI / 180)))) * Math.sin(rad);
      return {
        t,
        lat: Number(from.lat) + dLat,
        lon: Number(from.lon) + dLon,
        alt: alt,
        gs: gs,
        vs: null,
        track: track,
        squawk: sampleSquawkCode(from),
        callsign: from.cs || from.callsign || null
      };
    }

    if (before && after && Number(before.t) !== Number(after.t)) {
      const gap = Number(after.t) - Number(before.t);
      if (gap > 0 && gap <= 120) {
        const ratio = Math.max(0, Math.min(1, (t - Number(before.t)) / gap));
        const altBefore = sampleAltFt(before);
        const altAfter = sampleAltFt(after);
        const gsBefore = sampleGsKt(before);
        const gsAfter = sampleGsKt(after);
        const trackA = sampleTrackDeg(before);
        const trackB = sampleTrackDeg(after);
        let track = trackB ?? trackA;
        if (trackA !== null && trackB !== null) {
          let delta = trackB - trackA;
          while (delta > 180) delta -= 360;
          while (delta < -180) delta += 360;
          track = trackA + delta * ratio;
          if (track < 0) track += 360;
          if (track >= 360) track -= 360;
        }
        return {
          t,
          lat: Number(before.lat) + (Number(after.lat) - Number(before.lat)) * ratio,
          lon: Number(before.lon) + (Number(after.lon) - Number(before.lon)) * ratio,
          alt: altBefore !== null && altAfter !== null
            ? altBefore + (altAfter - altBefore) * ratio
            : (altAfter ?? altBefore),
          gs: gsBefore !== null && gsAfter !== null
            ? gsBefore + (gsAfter - gsBefore) * ratio
            : (gsAfter ?? gsBefore),
          vs: altBefore !== null && altAfter !== null && gap > 0
            ? ((altAfter - altBefore) / gap) * 60
            : null,
          track: track,
          squawk: sampleSquawkCode(before) || sampleSquawkCode(after),
          callsign: before.cs || after.cs || before.callsign || after.callsign || null
        };
      }
    }

    // Past newest sample or sparse gap: keep moving via groundspeed/track.
    if (before) {
      const dt = t - Number(before.t);
      if (dt >= 0) {
        const coast = deadReckon(before, dt);
        if (coast) return coast;
        return {
          t,
          lat: Number(before.lat),
          lon: Number(before.lon),
          alt: sampleAltFt(before),
          gs: sampleGsKt(before),
          vs: null,
          track: sampleTrackDeg(before),
          squawk: sampleSquawkCode(before),
          callsign: before.cs || before.callsign || null
        };
      }
    }

    const nearest = nearestByT(list, t, HOLD_SECONDS) || after || before;
    if (!nearest) return null;
    return {
      t: finite(nearest.t),
      lat: finite(nearest.lat),
      lon: finite(nearest.lon),
      alt: sampleAltFt(nearest),
      gs: sampleGsKt(nearest),
      vs: null,
      track: sampleTrackDeg(nearest),
      squawk: sampleSquawkCode(nearest),
      callsign: nearest.cs || nearest.callsign || null
    };
  }

  function trailOf(samples, t) {
    const start = t - TRAIL_SECONDS;
    return (Array.isArray(samples) ? samples : [])
      .filter((sample) => {
        const st = finite(sample && sample.t);
        return st !== null && st >= start && st <= t
          && finite(sample.lat) !== null && finite(sample.lon) !== null;
      })
      .sort((a, b) => Number(a.t) - Number(b.t))
      .map((sample) => [Number(sample.lat), Number(sample.lon)]);
  }

  function groupLegacyTraffic(rows) {
    const groups = {};
    (Array.isArray(rows) ? rows : []).forEach((row) => {
      const hex = String(row.hex || row.id || '').toLowerCase();
      if (!hex) return;
      if (!groups[hex]) {
        groups[hex] = {
          id: hex,
          callsign: String(row.cs || row.callsign || hex).toUpperCase(),
          samples: []
        };
      }
      if (row.cs || row.callsign) {
        groups[hex].callsign = String(row.cs || row.callsign).toUpperCase();
      }
      groups[hex].samples.push({
        t: finite(row.t),
        lat: finite(row.lat),
        lon: finite(row.lon),
        altBaroFt: finite(row.alt),
        trackTrueDeg: finite(row.trk),
        cs: row.cs || row.callsign || null
      });
    });
    return Object.values(groups);
  }

  function createController(root) {
    const mapEl = root.querySelector('#legs-track-map');
    const statusEl = root.querySelector('#legs-track-status');
    const timeline = root.querySelector('#legs-track-timeline');
    const playBtn = root.querySelector('#legs-track-play');
    const currentEl = root.querySelector('#legs-track-current');
    const endEl = root.querySelector('#legs-track-end');
    const openLink = root.querySelector('#legs-track-open-replay');
    const profileRoot = root.querySelector('#legs-track-profile');
    const profileSvg = root.querySelector('#legs-track-profile-svg');
    const profileMeta = root.querySelector('#legs-track-profile-meta');
    const centerBtn = root.querySelector('#legs-track-center');
    const ringsBtn = root.querySelector('#legs-track-rings');
    if (!mapEl || !statusEl || !timeline || !playBtn) {
      return { load() {}, reset() {}, invalidate() {} };
    }

    let map = null;
    let trackLayer = null;
    let dynamicLayer = null;
    let rangeRingLayer = null;
    let rangeRingCircles = [];
    let rangeRingLabels = [];
    let tileLayer = null;
    let ownshipSamples = [];
    let trafficAircraft = [];
    let liveNearby = [];
    let verticalProfile = null;
    let duration = 0;
    let currentT = 0;
    let playing = false;
    let raf = 0;
    let lastFrame = 0;
    let loadToken = 0;
    let visualStyle = 'default';
    let ownshipLabel = 'OWN';
    let ownshipColor = OWNSHIP_COLOR;
    let fullTrackColor = '#1d4ed8';
    let liveFollowActive = false;
    let livePollTimer = 0;
    let liveRaf = 0;
    let livePollFn = null;
    let followCentered = true;
    let rangeRingsEnabled = false;
    let lastRangeRingLat = null;
    let lastRangeRingLon = null;
    let replayMode = false;
    let suppressMapInteraction = false;
    let mapInteractionBound = false;
    let liveTracks = Object.create(null); // hex -> { item, samples:[{epoch,lat,lon,...}] }
    let liveOwnshipKey = 'ownship';
    let liveWindowStartEpoch = null;
    let liveMarkers = Object.create(null); // key -> L.Marker
    let liveMarkerMeta = Object.create(null); // key -> { hdg, alt, gs, label, squawk }
    let ownshipSquawk = null;
    let lastLiveTraceSignature = '';
    let lastLiveStatusAt = 0;
    let lastProfileRenderAt = 0;
    let archivedTrafficKeys = Object.create(null);
    let trafficHistoryLoaded = false;

    function setStatus(message, tone) {
      statusEl.textContent = message || '';
      statusEl.dataset.tone = tone || '';
    }

    function ensureMap() {
      if (map || typeof L === 'undefined') return map;
      map = L.map(mapEl, {
        zoomControl: true,
        attributionControl: true,
        scrollWheelZoom: true
      });
      tileLayer = addBasemapLayer(map, 'default');
      trackLayer = L.layerGroup().addTo(map);
      dynamicLayer = L.layerGroup().addTo(map);
      map.setView([33.63, -116.16], 9);
      bindMapInteraction();
      return map;
    }

    function updateCenterButton() {
      if (!centerBtn) return;
      centerBtn.hidden = visualStyle !== 'adsb' && !liveFollowActive;
      centerBtn.setAttribute('aria-pressed', followCentered ? 'true' : 'false');
      centerBtn.textContent = followCentered ? 'Centered on airplane' : 'Center on airplane';
    }

    function updateRingsButton() {
      if (!ringsBtn) return;
      ringsBtn.hidden = visualStyle !== 'adsb' && !liveFollowActive;
      ringsBtn.setAttribute('aria-pressed', rangeRingsEnabled ? 'true' : 'false');
      ringsBtn.textContent = rangeRingsEnabled ? 'Range rings on' : 'Range rings';
    }

    function setFollowCentered(enabled) {
      followCentered = !!enabled;
      updateCenterButton();
    }

    function clearRangeRings() {
      if (rangeRingLayer && map) {
        map.removeLayer(rangeRingLayer);
      }
      rangeRingLayer = null;
      rangeRingCircles = [];
      rangeRingLabels = [];
    }

    function setRangeRingsEnabled(enabled) {
      rangeRingsEnabled = !!enabled;
      updateRingsButton();
      if (!rangeRingsEnabled) {
        clearRangeRings();
        return;
      }
      if (lastRangeRingLat !== null && lastRangeRingLon !== null) {
        updateRangeRings(lastRangeRingLat, lastRangeRingLon);
      }
    }

    function updateRangeRings(lat, lon) {
      const plat = finite(lat);
      const plon = finite(lon);
      if (plat === null || plon === null) return;
      lastRangeRingLat = plat;
      lastRangeRingLon = plon;
      if (!rangeRingsEnabled || !ensureMap() || typeof L === 'undefined') {
        return;
      }
      if (!rangeRingLayer) {
        rangeRingLayer = L.layerGroup().addTo(map);
        RANGE_RINGS_NM.forEach((nm) => {
          const major = nm === 10 || nm === 20;
          const circle = L.circle([plat, plon], {
            radius: nm * NM_TO_METERS,
            color: OWNSHIP_COLOR,
            weight: major ? 1.7 : 1.15,
            opacity: major ? 0.85 : 0.65,
            fill: false,
            dashArray: major ? null : '5 5',
            interactive: false
          }).addTo(rangeRingLayer);
          const label = L.marker([plat + (nm / 60), plon], {
            interactive: false,
            keyboard: false,
            zIndexOffset: -200,
            icon: L.divIcon({
              className: 'legs-track-range-label',
              html: '<span>' + nm + ' NM</span>',
              iconSize: [48, 14],
              iconAnchor: [24, 7]
            })
          }).addTo(rangeRingLayer);
          rangeRingCircles.push(circle);
          rangeRingLabels.push({ marker: label, nm: nm });
        });
        return;
      }
      rangeRingCircles.forEach((circle) => {
        circle.setLatLng([plat, plon]);
      });
      rangeRingLabels.forEach((entry) => {
        entry.marker.setLatLng([plat + (entry.nm / 60), plon]);
      });
    }

    function bindMapInteraction() {
      if (!map || mapInteractionBound) return;
      mapInteractionBound = true;
      const releaseFollow = () => {
        if (suppressMapInteraction) return;
        if (followCentered) setFollowCentered(false);
      };
      map.on('dragstart', releaseFollow);
      map.on('zoomstart', releaseFollow);
    }

    function syncReplayControls() {
      if (!ownshipSamples.length) {
        duration = 0;
        timeline.disabled = true;
        playBtn.disabled = true;
        playBtn.textContent = 'Play';
        return;
      }
      duration = Math.max(0, Number(ownshipSamples[ownshipSamples.length - 1].t) || 0);
      timeline.min = '0';
      timeline.max = String(Math.max(1, duration));
      timeline.step = '0.1';
      timeline.disabled = false;
      playBtn.disabled = false;
      if (endEl) endEl.textContent = formatClock(duration);
      if (!playing) {
        playBtn.textContent = 'Play';
        playBtn.setAttribute('aria-pressed', 'false');
      }
      if (!replayMode && !playing && liveFollowActive) {
        if (currentEl) currentEl.textContent = 'LIVE';
        timeline.value = String(duration);
        currentT = duration;
      }
    }

    function enterReplayMode(fromT) {
      replayMode = true;
      setFollowCentered(false);
      const t = finite(fromT);
      if (t !== null) setTime(t, false);
      else if (!Number.isFinite(currentT) || currentT >= duration) setTime(0, false);
      else setTime(currentT, false);
      setStatus(
        'REPLAY · ' + ownshipLabel
          + (ownshipSamples.length ? (' · ' + ownshipSamples.length.toLocaleString() + ' pts') : ''),
        'ok'
      );
    }

    function exitReplayModeToLive() {
      stopPlayback();
      replayMode = false;
      setFollowCentered(true);
      syncReplayControls();
      if (liveFollowActive) renderLiveFrame();
      else if (ownshipSamples.length) {
        setTime(duration, false);
      }
      setStatus(
        (liveFollowActive ? 'LIVE · tracking ' : '') + ownshipLabel
          + (ownshipSamples.length ? (' · full trace ' + ownshipSamples.length.toLocaleString() + ' pts') : ''),
        'ok'
      );
    }

    function applyVisualStyle(style) {
      visualStyle = style === 'adsb' ? 'adsb' : 'default';
      if (!map) return;
      if (tileLayer) {
        map.removeLayer(tileLayer);
        tileLayer = null;
      }
      if (visualStyle === 'adsb') {
        ownshipColor = OWNSHIP_COLOR;
        fullTrackColor = OWNSHIP_COLOR;
        tileLayer = addBasemapLayer(map, 'adsb');
        mapEl.classList.add('is-adsb-style');
      } else {
        ownshipColor = OWNSHIP_COLOR;
        fullTrackColor = '#1d4ed8';
        tileLayer = addBasemapLayer(map, 'default');
        mapEl.classList.remove('is-adsb-style');
      }
      // LayerGroup itself has no bringToFront(); only front-capable child
      // layers may be raised. Calling it on the group broke live ADS-B.
      bringLayerGroupToFront(trackLayer);
      bringLayerGroupToFront(dynamicLayer);
      bringLayerGroupToFront(rangeRingLayer);
      updateCenterButton();
      updateRingsButton();
    }

    function stopPlayback() {
      playing = false;
      playBtn.textContent = 'Play';
      playBtn.setAttribute('aria-pressed', 'false');
      if (raf) {
        window.cancelAnimationFrame(raf);
        raf = 0;
      }
    }

    function setTime(t, fromInput) {
      currentT = Math.max(0, Math.min(duration || 0, Number(t) || 0));
      if (!fromInput) timeline.value = String(currentT);
      if (currentEl) currentEl.textContent = formatClock(currentT);
      renderFrame();
    }

    function pathUpTo(samples, t) {
      return (Array.isArray(samples) ? samples : [])
        .filter((sample) => {
          const st = finite(sample && sample.t);
          return st !== null && st <= t
            && finite(sample.lat) !== null && finite(sample.lon) !== null;
        })
        .sort((a, b) => Number(a.t) - Number(b.t))
        .map((sample) => [Number(sample.lat), Number(sample.lon)]);
    }

    function centerOnAircraft(lat, lon, zoom) {
      if (!map || lat === null || lon === null || !followCentered) return;
      // Preserve the user's zoom; only pan. Forcing zoom was fighting scroll-zoom.
      const z = Number.isFinite(Number(zoom)) ? Number(zoom) : (map.getZoom() || 11);
      suppressMapInteraction = true;
      map.setView([lat, lon], z, { animate: false });
      window.requestAnimationFrame(() => {
        suppressMapInteraction = false;
      });
    }

    function fullOwnshipPoints() {
      return ownshipSamples
        .filter((sample) => finite(sample.lat) !== null && finite(sample.lon) !== null)
        .sort((a, b) => Number(a.t) - Number(b.t))
        .map((sample) => [Number(sample.lat), Number(sample.lon)]);
    }

    function drawOwnshipFullTrace(alsoCenterLatest) {
      if (!trackLayer) return;
      trackLayer.clearLayers();
      const points = fullOwnshipPoints();
      if (points.length >= 2) {
        trackLayer.addLayer(L.polyline(points, {
          color: '#ffffff',
          weight: visualStyle === 'adsb' ? 8 : 7,
          opacity: 0.95,
          lineJoin: 'round',
          lineCap: 'round'
        }));
        trackLayer.addLayer(L.polyline(points, {
          color: visualStyle === 'adsb' ? OWNSHIP_COLOR : fullTrackColor,
          weight: 4,
          opacity: 0.9,
          lineJoin: 'round',
          lineCap: 'round'
        }));
      }
      if (alsoCenterLatest && points.length) {
        const tip = points[points.length - 1];
        centerOnAircraft(tip[0], tip[1], points.length >= 2 ? Math.max(map.getZoom() || 11, 11) : 12);
      } else if (alsoCenterLatest === false && points.length >= 2 && !liveFollowActive) {
        // Historical scrub: keep full route visible once when first loaded.
        map.fitBounds(L.latLngBounds(points).pad(0.18));
      }
    }

    function interpolateProfileCursor(points, t) {
      const list = (Array.isArray(points) ? points : [])
        .filter((p) => p && finite(p.t) !== null && finite(p.dist_nm) !== null)
        .sort((a, b) => Number(a.t) - Number(b.t));
      if (!list.length) return null;
      let before = null;
      let after = null;
      for (let i = 0; i < list.length; i++) {
        const point = list[i];
        if (Number(point.t) <= t) before = point;
        if (Number(point.t) >= t) {
          after = point;
          break;
        }
      }
      const blend = (a, b, ratio) => {
        const av = finite(a);
        const bv = finite(b);
        if (av !== null && bv !== null) return av + (bv - av) * ratio;
        return bv ?? av;
      };
      if (before && after && Number(before.t) !== Number(after.t)) {
        const gap = Number(after.t) - Number(before.t);
        const ratio = Math.max(0, Math.min(1, (t - Number(before.t)) / gap));
        const alt = blend(before.altitude_ft, after.altitude_ft, ratio);
        const terrain = blend(before.terrain_ft, after.terrain_ft, ratio);
        return {
          dist: Number(before.dist_nm) + (Number(after.dist_nm) - Number(before.dist_nm)) * ratio,
          alt: alt,
          terrain: terrain,
          agl: alt !== null && terrain !== null ? alt - terrain : blend(before.agl_ft, after.agl_ft, ratio)
        };
      }
      const hold = before || after;
      if (!hold) return null;
      const alt = finite(hold.altitude_ft);
      const terrain = finite(hold.terrain_ft);
      return {
        dist: Number(hold.dist_nm) || 0,
        alt: alt,
        terrain: terrain,
        agl: alt !== null && terrain !== null ? alt - terrain : finite(hold.agl_ft)
      };
    }

    function updateVerticalProfileLiveTip(position, epoch) {
      if (!verticalProfile || !Array.isArray(verticalProfile.points) || verticalProfile.points.length < 2) return;
      const lat = finite(position && position.lat);
      const lon = finite(position && position.lon);
      const alt = finite(position && position.altitude_ft);
      const observedEpoch = finite(epoch);
      if (lat === null || lon === null || alt === null || observedEpoch === null) return;

      const points = verticalProfile.points;
      const last = points[points.length - 1];
      const lastLat = finite(last && last.lat);
      const lastLon = finite(last && last.lon);
      const lastDist = finite(last && last.dist_nm) ?? 0;
      const stepNm = lastLat !== null && lastLon !== null
        ? haversineNm(lastLat, lastLon, lat, lon)
        : 0;
      const distNm = lastDist + stepNm;
      const t = liveWindowStartEpoch !== null
        ? Math.max(0, observedEpoch - liveWindowStartEpoch)
        : Math.max(0, (finite(last && last.t) ?? 0) + 1);

      let terrain = null;
      let nearestNm = Infinity;
      points.forEach((point) => {
        const pointTerrain = finite(point && point.terrain_ft);
        const pointLat = finite(point && point.lat);
        const pointLon = finite(point && point.lon);
        if (pointTerrain === null || pointLat === null || pointLon === null) return;
        const distance = haversineNm(pointLat, pointLon, lat, lon);
        if (distance < nearestNm) {
          nearestNm = distance;
          terrain = pointTerrain;
        }
      });
      if (nearestNm > 2.0) terrain = null;

      const livePoint = {
        t: t,
        epoch: observedEpoch,
        dist_nm: distNm,
        lat: lat,
        lon: lon,
        altitude_ft: alt,
        terrain_ft: terrain,
        agl_ft: terrain !== null ? alt - terrain : null,
        groundspeed_kt: finite(position && position.groundspeed_kt),
        track_deg: finite(position && position.track_deg)
      };

      const lastEpoch = finite(last && last.epoch);
      if (lastEpoch !== null && Math.abs(lastEpoch - observedEpoch) < 1) {
        points[points.length - 1] = livePoint;
      } else {
        points.push(livePoint);
      }
      verticalProfile.point_count = points.length;
      verticalProfile.distance_nm = distNm;
    }

    function renderVerticalProfile(options) {
      if (!profileRoot || !profileSvg) return;
      const opts = options || {};
      const points = verticalProfile && Array.isArray(verticalProfile.points)
        ? verticalProfile.points.filter((p) => p && finite(p.dist_nm) !== null)
        : [];
      if (points.length < 2) {
        profileRoot.hidden = true;
        profileSvg.innerHTML = '';
        if (profileMeta) profileMeta.textContent = 'Altitude · terrain clearance';
        return;
      }
      profileRoot.hidden = false;

      const width = 720;
      const height = 148;
      const padL = 48;
      const padR = 16;
      const padT = 18;
      const padB = 28;
      const plotW = width - padL - padR;
      const plotH = height - padT - padB;
      const maxDist = Math.max(0.5, Number(points[points.length - 1].dist_nm) || 0.5);

      let minAlt = Infinity;
      let maxAlt = -Infinity;
      points.forEach((p) => {
        const alt = finite(p.altitude_ft);
        const terr = finite(p.terrain_ft);
        if (alt !== null) {
          minAlt = Math.min(minAlt, alt);
          maxAlt = Math.max(maxAlt, alt);
        }
        if (terr !== null) {
          minAlt = Math.min(minAlt, terr);
          maxAlt = Math.max(maxAlt, terr);
        }
      });
      if (!Number.isFinite(minAlt) || !Number.isFinite(maxAlt)) {
        profileRoot.hidden = true;
        return;
      }
      minAlt -= 200;
      maxAlt += 400;
      const span = Math.max(400, maxAlt - minAlt);

      const xFor = (dist) => padL + (Math.max(0, Number(dist) || 0) / maxDist) * plotW;
      const yFor = (alt) => padT + (1 - ((Number(alt) - minAlt) / span)) * plotH;

      // Carry last known altitude across ground/null gaps so the descent line stays continuous.
      let lastAlt = null;
      let lastTerr = null;
      const series = points.map((p) => {
        const alt = finite(p.altitude_ft);
        const terr = finite(p.terrain_ft);
        if (alt !== null) lastAlt = alt;
        if (terr !== null) lastTerr = terr;
        return {
          t: finite(p.t),
          dist: Number(p.dist_nm) || 0,
          alt: alt !== null ? alt : lastAlt,
          terrain: terr !== null ? terr : lastTerr,
          agl: finite(p.agl_ft)
        };
      });
      // Back-fill leading null terrain from the first known sample.
      lastTerr = null;
      for (let i = series.length - 1; i >= 0; i--) {
        if (series[i].terrain !== null) lastTerr = series[i].terrain;
        else if (lastTerr !== null) series[i].terrain = lastTerr;
      }

      const terrainPts = [];
      series.forEach((p) => {
        const terr = p.terrain !== null ? p.terrain : minAlt;
        terrainPts.push(xFor(p.dist).toFixed(1) + ',' + yFor(terr).toFixed(1));
      });
      const terrainPoly = [
        xFor(0).toFixed(1) + ',' + (padT + plotH).toFixed(1),
        ...terrainPts,
        xFor(maxDist).toFixed(1) + ',' + (padT + plotH).toFixed(1)
      ].join(' ');

      const altPts = series
        .filter((p) => p.alt !== null)
        .map((p) => xFor(p.dist).toFixed(1) + ',' + yFor(p.alt).toFixed(1))
        .join(' ');

      const tip = series[series.length - 1];
      const scrubT = finite(opts.t);
      let cursor = null;
      if (scrubT !== null) {
        // Historic replay / delayed live: cursor follows scrubbed time along the profile.
        cursor = interpolateProfileCursor(points, scrubT);
      }
      if (!cursor && opts.liveSample && finite(opts.liveSample.altitude_ft) !== null) {
        const liveAlt = finite(opts.liveSample.altitude_ft);
        const liveTerr = tip ? tip.terrain : null;
        cursor = {
          dist: tip ? tip.dist : maxDist,
          alt: liveAlt,
          terrain: liveTerr,
          agl: liveAlt !== null && liveTerr !== null ? liveAlt - liveTerr : null
        };
      }
      if (!cursor) cursor = tip;

      const yTicks = [];
      const tickCount = 4;
      for (let i = 0; i <= tickCount; i++) {
        const alt = minAlt + (span * i) / tickCount;
        const y = yFor(alt);
        yTicks.push(
          '<line x1="' + padL + '" y1="' + y.toFixed(1) + '" x2="' + (width - padR) + '" y2="' + y.toFixed(1) + '" stroke="rgba(148,163,184,.18)" stroke-width="1"/>'
          + '<text x="' + (padL - 6) + '" y="' + (y + 3).toFixed(1) + '" text-anchor="end" fill="#94a3b8" font-size="10" font-weight="700">'
          + Math.round(alt).toLocaleString() + '</text>'
        );
      }

      const xLabels = [0, maxDist / 2, maxDist].map((d) => {
        const x = xFor(d);
        return '<text x="' + x.toFixed(1) + '" y="' + (height - 8) + '" text-anchor="middle" fill="#94a3b8" font-size="10" font-weight="700">'
          + (Math.round(d * 10) / 10) + ' NM</text>';
      });

      let cursorHtml = '';
      if (cursor && cursor.alt !== null) {
        const cx = xFor(cursor.dist);
        const cy = yFor(cursor.alt);
        const terrY = cursor.terrain !== null ? yFor(cursor.terrain) : (padT + plotH);
        cursorHtml = ''
          + '<line x1="' + cx.toFixed(1) + '" y1="' + padT + '" x2="' + cx.toFixed(1) + '" y2="' + (padT + plotH) + '" stroke="rgba(250,204,21,.45)" stroke-width="1.5" stroke-dasharray="3 3"/>'
          + '<line x1="' + cx.toFixed(1) + '" y1="' + cy.toFixed(1) + '" x2="' + cx.toFixed(1) + '" y2="' + terrY.toFixed(1) + '" stroke="rgba(34,197,94,.7)" stroke-width="2"/>'
          + '<circle cx="' + cx.toFixed(1) + '" cy="' + cy.toFixed(1) + '" r="4.5" fill="' + OWNSHIP_COLOR + '" stroke="#0f172a" stroke-width="1.5"/>';
      }

      profileSvg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
      profileSvg.innerHTML = ''
        + '<rect x="0" y="0" width="' + width + '" height="' + height + '" fill="transparent"/>'
        + yTicks.join('')
        + '<polygon points="' + terrainPoly + '" fill="rgba(34,197,94,.28)" stroke="rgba(74,222,128,.7)" stroke-width="1.2"/>'
        + (altPts ? '<polyline points="' + altPts + '" fill="none" stroke="' + OWNSHIP_COLOR + '" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>' : '')
        + cursorHtml
        + xLabels.join('')
        + '<text x="' + padL + '" y="12" fill="#cbd5e1" font-size="10" font-weight="700">MSL ft</text>';

      const tipAlt = cursor && cursor.alt !== null ? Math.round(cursor.alt).toLocaleString() + ' ft' : '--';
      const tipAgl = cursor && cursor.agl !== null ? Math.round(cursor.agl).toLocaleString() + ' ft AGL' : '-- AGL';
      const tipTerr = cursor && cursor.terrain !== null ? Math.round(cursor.terrain).toLocaleString() + ' ft terrain' : 'terrain n/a';
      const modeLabel = replayMode ? 'REPLAY' : ('LIVE −' + LIVE_DISPLAY_DELAY_S + 's');
      if (profileMeta) {
        profileMeta.textContent = modeLabel + ' · ' + tipAlt + ' · ' + tipAgl + ' · ' + tipTerr
          + ' · ' + (Math.round((cursor && cursor.dist != null ? cursor.dist : maxDist) * 10) / 10) + ' NM'
          + (verticalProfile.source === 'open-meteo' ? '' : ' · altitude only');
      }
    }

    function bearingDeg(lat1, lon1, lat2, lon2) {
      const toRad = Math.PI / 180;
      const φ1 = lat1 * toRad;
      const φ2 = lat2 * toRad;
      const Δλ = (lon2 - lon1) * toRad;
      const y = Math.sin(Δλ) * Math.cos(φ2);
      const x = Math.cos(φ1) * Math.sin(φ2) - Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ);
      let brng = Math.atan2(y, x) * 180 / Math.PI;
      if (brng < 0) brng += 360;
      return brng;
    }

    function appendTrackSample(key, item, sample) {
      if (!liveTracks[key]) {
        liveTracks[key] = {
          item: item || {},
          samples: []
        };
      }
      if (item) liveTracks[key].item = Object.assign({}, liveTracks[key].item, item);
      const lat = finite(sample && sample.lat);
      const lon = finite(sample && sample.lon);
      const epoch = finite(sample && sample.epoch) ?? (Date.now() / 1000);
      if (lat === null || lon === null) return;
      const samples = liveTracks[key].samples;
      const last = samples.length ? samples[samples.length - 1] : null;
      if (last && Math.abs(Number(last.epoch) - epoch) < 0.4
        && Math.abs(Number(last.lat) - lat) < 0.00001
        && Math.abs(Number(last.lon) - lon) < 0.00001) {
        // Refresh kinematics on a near-duplicate tip.
        if (finite(sample && (sample.groundspeed_kt ?? sample.gs)) !== null) {
          last.groundspeed_kt = finite(sample.groundspeed_kt ?? sample.gs);
        }
        if (finite(sample && (sample.track_deg ?? sample.track ?? sample.heading_deg)) !== null) {
          last.track_deg = finite(sample.track_deg ?? sample.track ?? sample.heading_deg);
        }
        if (finite(sample && (sample.altitude_ft ?? sample.alt)) !== null) {
          last.altitude_ft = finite(sample.altitude_ft ?? sample.alt);
        }
        if (formatSquawk(sample && sample.squawk)) {
          last.squawk = formatSquawk(sample.squawk);
        }
        return;
      }

      let gs = finite(sample && (sample.groundspeed_kt ?? sample.gs));
      let track = finite(sample && (sample.track_deg ?? sample.track ?? sample.heading_deg));
      if (last && Number(epoch) > Number(last.epoch)) {
        const dt = Number(epoch) - Number(last.epoch);
        if (dt > 0.2 && dt < 120) {
          const distNm = tv_adsb_haversine_nm_client(Number(last.lat), Number(last.lon), lat, lon);
          if (gs === null && distNm > 0.001) {
            gs = (distNm / dt) * 3600;
          }
          if (track === null && distNm > 0.001) {
            track = bearingDeg(Number(last.lat), Number(last.lon), lat, lon);
          }
          // Carry forward prior kinematics when stationary update lacks them.
          if (gs === null) gs = finite(last.groundspeed_kt);
          if (track === null) track = finite(last.track_deg);
        } else {
          if (gs === null) gs = finite(last.groundspeed_kt);
          if (track === null) track = finite(last.track_deg);
        }
      }

      samples.push({
        epoch: epoch,
        lat: lat,
        lon: lon,
        altitude_ft: finite(sample && (sample.altitude_ft ?? sample.alt)),
        groundspeed_kt: gs,
        track_deg: track,
        squawk: formatSquawk(sample && sample.squawk) || (last ? formatSquawk(last.squawk) : null),
        category: sample && sample.category ? sample.category : null
      });
      // Keep ~45 minutes of live buffer.
      const cutoff = epoch - (45 * 60);
      while (samples.length > 2 && Number(samples[0].epoch) < cutoff) {
        samples.shift();
      }
    }

    function tv_adsb_haversine_nm_client(lat1, lon1, lat2, lon2) {
      const toRad = Math.PI / 180;
      const dLat = (lat2 - lat1) * toRad;
      const dLon = (lon2 - lon1) * toRad;
      const a = Math.sin(dLat / 2) ** 2
        + Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLon / 2) ** 2;
      return 3440.065 * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function interpolateByEpoch(samples, epoch) {
      const list = (Array.isArray(samples) ? samples : [])
        .filter((s) => finite(s && s.epoch) !== null && finite(s && s.lat) !== null && finite(s && s.lon) !== null)
        .sort((a, b) => Number(a.epoch) - Number(b.epoch));
      if (!list.length) return null;

      // Fill missing gs/track from neighboring points so coasting never freezes.
      for (let i = 0; i < list.length; i++) {
        const cur = list[i];
        if (finite(cur.groundspeed_kt) !== null && finite(cur.track_deg) !== null) continue;
        const prev = i > 0 ? list[i - 1] : null;
        const next = i + 1 < list.length ? list[i + 1] : null;
        const a = prev || cur;
        const b = next || cur;
        if (a !== b) {
          const dt = Math.max(0.2, Number(b.epoch) - Number(a.epoch));
          const distNm = tv_adsb_haversine_nm_client(Number(a.lat), Number(a.lon), Number(b.lat), Number(b.lon));
          if (finite(cur.groundspeed_kt) === null && distNm > 0.001) {
            cur.groundspeed_kt = (distNm / dt) * 3600;
          }
          if (finite(cur.track_deg) === null && distNm > 0.001) {
            cur.track_deg = bearingDeg(Number(a.lat), Number(a.lon), Number(b.lat), Number(b.lon));
          }
        }
        if (finite(cur.groundspeed_kt) === null && prev) cur.groundspeed_kt = finite(prev.groundspeed_kt);
        if (finite(cur.track_deg) === null && prev) cur.track_deg = finite(prev.track_deg);
      }

      let before = null;
      let after = null;
      for (let i = 0; i < list.length; i++) {
        const sample = list[i];
        if (Number(sample.epoch) <= epoch) before = sample;
        if (Number(sample.epoch) >= epoch) {
          after = sample;
          break;
        }
      }

      function deadReckon(from, dtSec) {
        if (!from) return null;
        let gs = finite(from.groundspeed_kt);
        let track = finite(from.track_deg);
        if (gs === null || track === null) return null;
        const dt = Math.max(0, Math.min(45, dtSec));
        if (dt <= 0) {
          return {
            lat: Number(from.lat),
            lon: Number(from.lon),
            altitude_ft: finite(from.altitude_ft),
            groundspeed_kt: gs,
            track_deg: track,
            squawk: formatSquawk(from.squawk),
            category: from.category || null
          };
        }
        const distNm = (gs * dt) / 3600;
        const rad = track * Math.PI / 180;
        const dLat = (distNm / 60) * Math.cos(rad);
        const dLon = (distNm / (60 * Math.max(0.2, Math.cos(Number(from.lat) * Math.PI / 180)))) * Math.sin(rad);
        return {
          lat: Number(from.lat) + dLat,
          lon: Number(from.lon) + dLon,
          altitude_ft: finite(from.altitude_ft),
          groundspeed_kt: gs,
          track_deg: track,
          squawk: formatSquawk(from.squawk),
          category: from.category || null
        };
      }

      if (before && after && Number(before.epoch) !== Number(after.epoch)) {
        const gap = Number(after.epoch) - Number(before.epoch);
        if (gap > 0 && gap <= 120) {
          const ratio = Math.max(0, Math.min(1, (epoch - Number(before.epoch)) / gap));
          const trackA = finite(before.track_deg);
          const trackB = finite(after.track_deg);
          let track = trackB ?? trackA;
          if (trackA !== null && trackB !== null) {
            let delta = trackB - trackA;
            while (delta > 180) delta -= 360;
            while (delta < -180) delta += 360;
            track = trackA + delta * ratio;
            if (track < 0) track += 360;
            if (track >= 360) track -= 360;
          }
          return {
            lat: Number(before.lat) + (Number(after.lat) - Number(before.lat)) * ratio,
            lon: Number(before.lon) + (Number(after.lon) - Number(before.lon)) * ratio,
            altitude_ft: finite(before.altitude_ft) !== null && finite(after.altitude_ft) !== null
              ? Number(before.altitude_ft) + (Number(after.altitude_ft) - Number(before.altitude_ft)) * ratio
              : (finite(after.altitude_ft) ?? finite(before.altitude_ft)),
            groundspeed_kt: finite(before.groundspeed_kt) !== null && finite(after.groundspeed_kt) !== null
              ? Number(before.groundspeed_kt) + (Number(after.groundspeed_kt) - Number(before.groundspeed_kt)) * ratio
              : (finite(after.groundspeed_kt) ?? finite(before.groundspeed_kt)),
            track_deg: track,
            squawk: formatSquawk(before.squawk) || formatSquawk(after.squawk),
            category: after.category || before.category || null
          };
        }
      }

      // Past the newest sample (or sparse gap): keep moving via groundspeed/track.
      if (before) {
        const dt = epoch - Number(before.epoch);
        if (dt >= 0) {
          const coast = deadReckon(before, dt);
          if (coast) return coast;
          return {
            lat: Number(before.lat),
            lon: Number(before.lon),
            altitude_ft: finite(before.altitude_ft),
            groundspeed_kt: finite(before.groundspeed_kt),
            track_deg: finite(before.track_deg),
            squawk: formatSquawk(before.squawk),
            category: before.category || null
          };
        }
      }

      if (after) {
        return {
          lat: Number(after.lat),
          lon: Number(after.lon),
          altitude_ft: finite(after.altitude_ft),
          groundspeed_kt: finite(after.groundspeed_kt),
          track_deg: finite(after.track_deg),
          squawk: formatSquawk(after.squawk),
          category: after.category || null
        };
      }
      return null;
    }

    function clearLiveMarkers() {
      Object.keys(liveMarkers).forEach((key) => {
        const marker = liveMarkers[key];
        if (marker && dynamicLayer) dynamicLayer.removeLayer(marker);
        delete liveMarkers[key];
        delete liveMarkerMeta[key];
      });
      liveMarkers = Object.create(null);
      liveMarkerMeta = Object.create(null);
    }

    function upsertLiveMarker(key, lat, lon, icon, zIndexOffset, meta) {
      let marker = liveMarkers[key];
      const nextMeta = meta || {};
      const prevMeta = liveMarkerMeta[key] || {};
      const iconDirty = !marker
        || Math.round(Number(prevMeta.hdg) || 0) !== Math.round(Number(nextMeta.hdg) || 0)
        || Math.round((Number(prevMeta.alt) || 0) / 25) !== Math.round((Number(nextMeta.alt) || 0) / 25)
        || Math.round((Number(prevMeta.gs) || 0) / 5) !== Math.round((Number(nextMeta.gs) || 0) / 5)
        || String(prevMeta.label || '') !== String(nextMeta.label || '')
        || String(prevMeta.squawk || '') !== String(nextMeta.squawk || '');
      if (!marker) {
        marker = L.marker([lat, lon], {
          icon: icon,
          zIndexOffset: zIndexOffset || 0
        }).addTo(dynamicLayer);
        liveMarkers[key] = marker;
      } else {
        marker.setLatLng([lat, lon]);
        if (iconDirty) marker.setIcon(icon);
        if (Number.isFinite(zIndexOffset)) marker.setZIndexOffset(zIndexOffset);
      }
      liveMarkerMeta[key] = nextMeta;
      return marker;
    }

    function renderLiveFrame() {
      if (!dynamicLayer || !map) return;
      const nowEpoch = Date.now() / 1000;
      // Intentional lag so motion stays continuous between ADS-B polls.
      const renderEpoch = nowEpoch - LIVE_DISPLAY_DELAY_S;

      const tipSample = ownshipSamples.length ? ownshipSamples[ownshipSamples.length - 1] : null;
      const tipLat = tipSample ? finite(tipSample.lat) : null;
      const tipLon = tipSample ? finite(tipSample.lon) : null;
      const traceSignature = (tipLat !== null && tipLon !== null)
        ? (String(ownshipSamples.length) + ':' + tipLat.toFixed(3) + ',' + tipLon.toFixed(3))
        : '';
      if (traceSignature !== lastLiveTraceSignature) {
        lastLiveTraceSignature = traceSignature;
        drawOwnshipFullTrace(false);
      }

      const seen = Object.create(null);
      const own = liveTracks[liveOwnshipKey];
      const ownSample = own ? interpolateByEpoch(own.samples, renderEpoch) : null;
      if (ownSample) {
        seen[liveOwnshipKey] = true;
        const ownSquawk = formatSquawk(ownSample.squawk) || ownshipSquawk;
        upsertLiveMarker(
          liveOwnshipKey,
          ownSample.lat,
          ownSample.lon,
          archiveAircraftIcon(
            Object.assign({ callsign: ownshipLabel, registration: ownshipLabel, squawk: ownSquawk }, own.item || {}),
            Object.assign({}, ownSample, { squawk: ownSquawk }),
            OWNSHIP_COLOR
          ),
          800,
          {
            hdg: ownSample.track_deg,
            alt: ownSample.altitude_ft,
            gs: ownSample.groundspeed_kt,
            label: ownshipLabel,
            squawk: ownSquawk || ''
          }
        );
        updateRangeRings(ownSample.lat, ownSample.lon);
        // Continuous follow: pan every frame so the tracked airplane never
        // appears to stop while the map snaps back on each poll.
        if (followCentered) {
          centerOnAircraft(ownSample.lat, ownSample.lon);
        }
      }

      let nearbyCount = 0;
      Object.keys(liveTracks).forEach((key) => {
        if (key === liveOwnshipKey) return;
        const track = liveTracks[key];
        const sample = interpolateByEpoch(track.samples, renderEpoch);
        if (!sample) return;
        nearbyCount += 1;
        seen[key] = true;
        const label = String((track.item && (track.item.callsign || track.item.registration || track.item.hex)) || key);
        upsertLiveMarker(
          key,
          sample.lat,
          sample.lon,
          archiveAircraftIcon(track.item || { hex: key }, sample),
          200,
          {
            hdg: sample.track_deg,
            alt: sample.altitude_ft,
            gs: sample.groundspeed_kt,
            label: label,
            squawk: formatSquawk(sample.squawk) || ''
          }
        );
      });

      Object.keys(liveMarkers).forEach((key) => {
        if (seen[key]) return;
        dynamicLayer.removeLayer(liveMarkers[key]);
        delete liveMarkers[key];
        delete liveMarkerMeta[key];
      });

      if ((nowEpoch - lastLiveStatusAt) >= 1.0) {
        lastLiveStatusAt = nowEpoch;
        setStatus(
          'LIVE −' + LIVE_DISPLAY_DELAY_S + 's · ' + ownshipLabel
            + (ownshipSamples.length ? (' · full trace ' + ownshipSamples.length.toLocaleString() + ' pts') : '')
            + (nearbyCount ? (' · ' + nearbyCount + ' nearby') : ''),
          'ok'
        );
      }
      // Vertical profile cursor: throttle SVG rebuilds, but keep time continuous.
      if ((nowEpoch - lastProfileRenderAt) >= 0.1) {
        lastProfileRenderAt = nowEpoch;
        const delayedT = liveWindowStartEpoch !== null
          ? Math.max(0, renderEpoch - liveWindowStartEpoch)
          : (duration > 0 ? duration : null);
        renderVerticalProfile({
          t: delayedT,
          liveSample: ownSample
        });
      }
    }

    function stopLiveFollow() {
      liveFollowActive = false;
      livePollFn = null;
      if (livePollTimer) {
        window.clearInterval(livePollTimer);
        livePollTimer = 0;
      }
      if (liveRaf) {
        window.cancelAnimationFrame(liveRaf);
        liveRaf = 0;
      }
      clearLiveMarkers();
      lastLiveTraceSignature = '';
    }

    function liveTick() {
      if (!liveFollowActive) return;
      try {
        // Historic scrub/play owns the markers while replayMode is on.
        if (!replayMode) renderLiveFrame();
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'Live map render failed.', 'error');
      }
      liveRaf = window.requestAnimationFrame(liveTick);
    }

    function ingestLiveSnapshot(payload) {
      try {
        ingestLiveSnapshotUnsafe(payload);
      } catch (error) {
        // Never let map/profile failures kill the schedule ADS-B status panel.
        setStatus(error instanceof Error ? error.message : 'Live map update failed.', 'error');
      }
    }

    function ingestLiveSnapshotUnsafe(payload) {
      const data = payload || {};
      const position = data.position || null;
      const aircraft = data.aircraft || {};
      const registration = String(aircraft.registration || ownshipLabel || '').toUpperCase();
      if (registration) ownshipLabel = registration;

      if (data.vertical_profile && Array.isArray(data.vertical_profile.points) && data.vertical_profile.points.length >= 2) {
        const incoming = data.vertical_profile;
        const incomingHasTerrain = String(incoming.source || '') === 'open-meteo'
          || incoming.points.some((p) => p && finite(p.terrain_ft) !== null);
        const currentHasTerrain = !!(verticalProfile
          && Array.isArray(verticalProfile.points)
          && (String(verticalProfile.source || '') === 'open-meteo'
            || verticalProfile.points.some((p) => p && finite(p.terrain_ft) !== null)));
        // Never let a failed/empty elevation refresh wipe a good terrain profile.
        if (incomingHasTerrain || !currentHasTerrain) {
          verticalProfile = incoming;
        } else {
          // Keep nearby terrain only; never stretch the last cached elevation across new track.
          if (incoming.points.length >= verticalProfile.points.length) {
            const oldPts = verticalProfile.points;
            const nearestOldTerrain = (lat, lon) => {
              const plat = finite(lat);
              const plon = finite(lon);
              if (plat === null || plon === null) return null;
              let best = null;
              let bestNm = Infinity;
              for (let i = 0; i < oldPts.length; i++) {
                const old = oldPts[i];
                const terr = finite(old && old.terrain_ft);
                const olat = finite(old && old.lat);
                const olon = finite(old && old.lon);
                if (terr === null || olat === null || olon === null) continue;
                const nm = haversineNm(olat, olon, plat, plon);
                if (nm < bestNm) {
                  bestNm = nm;
                  best = terr;
                }
              }
              return bestNm <= 1.5 ? best : null;
            };
            verticalProfile = {
              point_count: incoming.points.length,
              distance_nm: incoming.distance_nm,
              source: 'open-meteo',
              points: incoming.points.map((p) => {
                const terrain = finite(p.terrain_ft) !== null
                  ? finite(p.terrain_ft)
                  : nearestOldTerrain(p.lat, p.lon);
                const alt = finite(p.altitude_ft);
                return Object.assign({}, p, {
                  terrain_ft: terrain,
                  agl_ft: alt !== null && terrain !== null ? Math.round(alt - terrain) : (finite(p.agl_ft) ?? null)
                });
              })
            };
          }
        }
      }
      // Historical traffic once (archive) — used for replay + delayed live interpolation.
      if (!trafficHistoryLoaded && Array.isArray(data.traffic) && data.traffic.length) {
        trafficHistoryLoaded = true;
        trafficAircraft = data.traffic.map((item) => {
          const hex = String((item && (item.hex || item.id)) || '').toLowerCase();
          const callsign = String((item && (item.callsign || item.registration || hex)) || '').toUpperCase();
          const mappedTraffic = (Array.isArray(item.samples) ? item.samples : []).map((sample) => ({
            t: finite(sample && sample.t),
            epoch: finite(sample && sample.epoch),
            lat: finite(sample && sample.lat),
            lon: finite(sample && sample.lon),
            altitude_ft: finite(sample && sample.altitude_ft),
            altBaroFt: finite(sample && sample.altitude_ft),
            groundspeed_kt: finite(sample && sample.groundspeed_kt),
            gs: finite(sample && sample.groundspeed_kt),
            track_deg: finite(sample && sample.track_deg),
            trackTrueDeg: finite(sample && sample.track_deg),
            heading_deg: finite(sample && sample.track_deg),
            squawk: formatSquawk(sample && sample.squawk)
          })).filter((s) => s.t !== null && s.lat !== null && s.lon !== null);
          if (hex) archivedTrafficKeys[hex] = true;
          mappedTraffic.forEach((sample) => {
            if (!hex || sample.epoch === null) return;
            appendTrackSample(hex, {
              hex: hex,
              callsign: callsign,
              registration: callsign
            }, {
              epoch: sample.epoch,
              lat: sample.lat,
              lon: sample.lon,
              altitude_ft: sample.altitude_ft,
              groundspeed_kt: sample.groundspeed_kt,
              track_deg: sample.track_deg,
              squawk: sample.squawk
            });
          });
          return {
            id: hex || callsign,
            callsign: callsign,
            samples: mappedTraffic
          };
        }).filter((item) => item.samples.length >= 2);
      }
      const samples = data.track && Array.isArray(data.track.samples) ? data.track.samples : [];
      if (samples.length) {
        const mapped = samples.map((sample) => ({
          t: finite(sample && sample.t),
          lat: finite(sample && sample.lat),
          lon: finite(sample && sample.lon),
          altitude_ft: finite(sample && sample.altitude_ft),
          altBaroFt: finite(sample && sample.altitude_ft),
          groundspeed_kt: finite(sample && sample.groundspeed_kt),
          gs: finite(sample && sample.groundspeed_kt),
          heading_deg: finite(sample && sample.track_deg),
          track_deg: finite(sample && sample.track_deg),
          trackTrueDeg: finite(sample && sample.track_deg),
          squawk: formatSquawk(sample && sample.squawk),
          epoch: finite(sample && sample.epoch)
        })).filter((s) => s.t !== null && s.lat !== null && s.lon !== null);
        // Always take the longer / fresher server trace so the full track remains complete.
        if (mapped.length >= ownshipSamples.length) {
          ownshipSamples = mapped;
        }
        mapped.forEach((sample) => {
          appendTrackSample(liveOwnshipKey, {
            callsign: ownshipLabel,
            registration: ownshipLabel,
            hex: aircraft.adsb_hex || ''
          }, {
            epoch: sample.epoch ?? ((liveWindowStartEpoch || (Date.now() / 1000 - Math.max(0, sample.t))) + sample.t),
            lat: sample.lat,
            lon: sample.lon,
            altitude_ft: sample.altitude_ft,
            groundspeed_kt: sample.groundspeed_kt,
            track_deg: sample.track_deg,
            squawk: sample.squawk
          });
          if (sample.squawk) ownshipSquawk = sample.squawk;
        });
      }

      if (position && finite(position.lat) !== null && finite(position.lon) !== null) {
        const observedMs = position.observed_at ? Date.parse(String(position.observed_at)) : NaN;
        const epoch = Number.isFinite(observedMs) ? observedMs / 1000 : Date.now() / 1000;
        if (formatSquawk(position.squawk)) ownshipSquawk = formatSquawk(position.squawk);
        appendTrackSample(liveOwnshipKey, {
          callsign: ownshipLabel,
          registration: ownshipLabel,
          hex: aircraft.adsb_hex || ''
        }, {
          epoch: epoch,
          lat: position.lat,
          lon: position.lon,
          altitude_ft: position.altitude_ft,
          groundspeed_kt: position.groundspeed_kt,
          track_deg: position.track_deg,
          squawk: ownshipSquawk
        });
        updateVerticalProfileLiveTip(position, epoch);
        // Extend the full visible polyline only when the live tip moved.
        const last = ownshipSamples.length ? ownshipSamples[ownshipSamples.length - 1] : null;
        const moved = !last
          || Math.abs(Number(last.lat) - Number(position.lat)) > 0.00005
          || Math.abs(Number(last.lon) - Number(position.lon)) > 0.00005;
        if (moved) {
          const tipT = liveWindowStartEpoch !== null
            ? Math.max(0, epoch - liveWindowStartEpoch)
            : (last ? Math.max(Number(last.t) || 0, 0) + 1 : 0);
          ownshipSamples.push({
            t: tipT,
            lat: Number(position.lat),
            lon: Number(position.lon),
            altitude_ft: finite(position.altitude_ft),
            groundspeed_kt: finite(position.groundspeed_kt),
            track_deg: finite(position.track_deg),
            trackTrueDeg: finite(position.track_deg),
            squawk: ownshipSquawk,
            epoch: epoch
          });
        }
      }

      const seen = Object.create(null);
      seen[liveOwnshipKey] = true;
      (Array.isArray(data.nearby) ? data.nearby : []).forEach((row) => {
        const key = String(row.hex || row.registration || row.callsign || '').toLowerCase();
        if (!key) return;
        seen[key] = true;
        appendTrackSample(key, {
          hex: row.hex || '',
          registration: row.registration || '',
          callsign: row.callsign || row.registration || row.hex || ''
        }, {
          epoch: Date.now() / 1000,
          lat: row.lat,
          lon: row.lon,
          altitude_ft: row.altitude_ft,
          groundspeed_kt: row.groundspeed_kt,
          track_deg: row.track_deg,
          category: row.category || null,
          squawk: formatSquawk(row.squawk)
        });
      });
      Object.keys(liveTracks).forEach((key) => {
        if (seen[key] || key === liveOwnshipKey || archivedTrafficKeys[key]) return;
        delete liveTracks[key];
      });

      liveNearby = Array.isArray(data.nearby) ? data.nearby : [];
      drawOwnshipFullTrace(false);
      syncReplayControls();
      if (!replayMode) {
        const delayedT = liveWindowStartEpoch !== null
          ? Math.max(0, (Date.now() / 1000) - LIVE_DISPLAY_DELAY_S - liveWindowStartEpoch)
          : (duration > 0 ? duration : null);
        renderVerticalProfile({ t: delayedT, liveSample: position });
      } else {
        renderVerticalProfile({ t: currentT });
      }
      // Map follow is handled continuously in renderLiveFrame while centered.
      if (!liveRaf) liveRaf = window.requestAnimationFrame(liveTick);
    }

    async function pollLiveOnce() {
      if (!liveFollowActive || typeof livePollFn !== 'function') return;
      try {
        const snapshot = await livePollFn();
        if (!liveFollowActive) return;
        if (snapshot) ingestLiveSnapshot(snapshot);
      } catch (error) {
        if (!liveFollowActive) return;
        setStatus(error instanceof Error ? error.message : 'Live ADS-B update failed.', 'error');
      }
    }

    function startLiveFollow(options) {
      const opts = options || {};
      stopPlayback();
      stopLiveFollow();
      ensureMap();
      if (map && typeof map.invalidateSize === 'function') {
        try { map.invalidateSize(); } catch (e) { /* dialog may still be animating */ }
      }
      applyVisualStyle('adsb');
      replayMode = false;
      setFollowCentered(opts.follow !== false);
      ownshipLabel = String(opts.label || ownshipLabel || 'Aircraft').toUpperCase();
      liveFollowActive = true;
      livePollFn = typeof opts.pollFn === 'function' ? opts.pollFn : null;
      liveTracks = Object.create(null);
      liveOwnshipKey = 'ownship';
      liveWindowStartEpoch = finite(opts.windowStartEpoch) ?? (Date.now() / 1000 - 6 * 3600);
      archivedTrafficKeys = Object.create(null);
      trafficHistoryLoaded = false;
      ownshipSquawk = null;
      trafficAircraft = [];
      try {
        if (opts.initialSnapshot) ingestLiveSnapshot(opts.initialSnapshot);
        else if (Array.isArray(opts.samples) && opts.samples.length) {
          ingestLiveSnapshot({
            aircraft: { registration: ownshipLabel },
            track: { samples: opts.samples },
            nearby: opts.nearby || [],
            position: opts.position || null
          });
        }
        syncReplayControls();
        updateCenterButton();
        updateRingsButton();
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'Live map setup failed.', 'error');
      }
      // Defer first poll so the opening request cannot re-enter startLiveFollow.
      window.setTimeout(function () {
        if (liveFollowActive) pollLiveOnce();
      }, 50);
      livePollTimer = window.setInterval(pollLiveOnce, Math.max(2000, Number(opts.pollMs) || 3500));
      if (!liveRaf) liveRaf = window.requestAnimationFrame(liveTick);
      setStatus('LIVE · tracking ' + ownshipLabel, 'ok');
    }

    function renderFrame() {
      if (!dynamicLayer) return;
      dynamicLayer.clearLayers();

      if (liveFollowActive && !replayMode) {
        renderLiveFrame();
        return;
      }

      // Full selected-aircraft trace stays on trackLayer.
      const flown = pathUpTo(ownshipSamples, currentT);
      if (flown.length >= 2) {
        L.polyline(flown, {
          color: visualStyle === 'adsb' ? '#ffffff' : '#0f766e',
          weight: visualStyle === 'adsb' ? 7 : 5,
          opacity: 0.95,
          lineJoin: 'round',
          lineCap: 'round'
        }).addTo(dynamicLayer);
        if (visualStyle === 'adsb') {
          L.polyline(flown, {
            color: OWNSHIP_COLOR,
            weight: 4,
            opacity: 0.95,
            lineJoin: 'round',
            lineCap: 'round'
          }).addTo(dynamicLayer);
        }
      }

      const ownship = interpolateByT(ownshipSamples, currentT);
      if (ownship && ownship.lat !== null && ownship.lon !== null) {
        const ownSquawk = formatSquawk(ownship.squawk) || ownshipSquawk;
        L.marker([ownship.lat, ownship.lon], {
          icon: archiveAircraftIcon(
            { callsign: ownshipLabel, registration: ownshipLabel, squawk: ownSquawk },
            {
              track_deg: ownship.track,
              groundspeed_kt: ownship.gs,
              altitude_ft: ownship.alt,
              squawk: ownSquawk
            },
            visualStyle === 'adsb' ? OWNSHIP_COLOR : ownshipColor
          ),
          zIndexOffset: 500
        }).addTo(dynamicLayer);
        updateRangeRings(ownship.lat, ownship.lon);
        if (visualStyle === 'adsb' && followCentered) {
          centerOnAircraft(ownship.lat, ownship.lon);
        }
      }

      let trafficCount = 0;
      // Historical traffic at the scrubbed time (never current live positions during replay).
      trafficAircraft.forEach((aircraft) => {
        const sample = interpolateByT(aircraft.samples, currentT);
        if (!sample || sample.lat === null || sample.lon === null) return;
        trafficCount += 1;
        L.marker([sample.lat, sample.lon], {
          icon: archiveAircraftIcon(
            { callsign: aircraft.callsign || aircraft.id, hex: aircraft.id, squawk: sample.squawk },
            {
              track_deg: sample.track,
              groundspeed_kt: sample.gs,
              altitude_ft: sample.alt,
              squawk: sample.squawk,
              category: aircraft.category || ''
            }
          )
        }).addTo(dynamicLayer);
      });

      // Raw live nearby snapshot only outside ADS-B historic replay.
      if (!replayMode && visualStyle !== 'adsb') {
        liveNearby.forEach((target) => {
          const lat = finite(target.lat);
          const lon = finite(target.lon);
          if (lat === null || lon === null) return;
          trafficCount += 1;
          L.marker([lat, lon], {
            icon: archiveAircraftIcon(target, {
              track_deg: target.track_deg,
              groundspeed_kt: target.groundspeed_kt,
              altitude_ft: target.altitude_ft,
              squawk: target.squawk,
              category: target.category || ''
            }, target.color || undefined),
            zIndexOffset: 200
          }).addTo(dynamicLayer);
        });
      }

      if (ownshipSamples.length && visualStyle === 'adsb') {
        setStatus(
          (replayMode ? 'REPLAY · ' : '') + ownshipLabel + ' · full trace ' + ownshipSamples.length.toLocaleString() + ' pts'
            + (trafficCount ? (' · ' + trafficCount + ' traffic') : '')
            + ' · ' + formatClock(currentT),
          'ok'
        );
      } else if (ownshipSamples.length) {
        setStatus(
          'Ownship track remains visible · ' + trafficCount + ' traffic target'
            + (trafficCount === 1 ? '' : 's')
            + ' at ' + formatClock(currentT) + '.',
          'ok'
        );
      }
      if (visualStyle === 'adsb') {
        renderVerticalProfile({ t: currentT });
      }
    }

    function drawStaticTrack() {
      drawOwnshipFullTrace(visualStyle === 'adsb');
    }

    function tick(now) {
      if (!playing) return;
      const delta = Math.min(0.25, (now - lastFrame) / 1000);
      lastFrame = now;
      const next = currentT + (delta * PLAY_RATE);
      if (next >= duration) {
        setTime(duration);
        stopPlayback();
        return;
      }
      setTime(next);
      raf = window.requestAnimationFrame(tick);
    }

    function startPlayback() {
      if (!ownshipSamples.length || duration <= 0) return;
      if (currentT >= duration) setTime(0);
      playing = true;
      playBtn.textContent = 'Pause';
      playBtn.setAttribute('aria-pressed', 'true');
      lastFrame = performance.now();
      raf = window.requestAnimationFrame(tick);
    }

    async function fetchJson(url) {
      const response = await fetch(url, { credentials: 'same-origin' });
      const text = await response.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (error) {
        throw new Error('Replay response was not valid JSON.');
      }
      if (!response.ok || !data || data.ok === false) {
        throw new Error((data && data.error) || ('HTTP ' + response.status));
      }
      return data;
    }

    async function loadSamples(recordingUid, stride) {
      const samples = [];
      let offset = 0;
      const limit = 8000;
      for (let guard = 0; guard < 40; guard++) {
        const url = '/api/recordings/replay.php?id=' + encodeURIComponent(recordingUid)
          + '&version=2&samples=1&compact=1&sample_stride=' + stride
          + '&offset=' + offset + '&limit=' + limit;
        const chunk = await fetchJson(url);
        const rows = Array.isArray(chunk.samples) ? chunk.samples : [];
        rows.forEach((row) => {
          const lat = finite(row.lat);
          const lon = finite(row.lon);
          const t = finite(row.t);
          if (lat === null || lon === null || t === null) return;
          samples.push({
            t,
            lat,
            lon,
            altitude_ft: finite(row.altitude_ft ?? row.baro_altitude_ft ?? row.altitude_ft_msl),
            heading_deg: finite(row.heading_deg ?? row.true_heading_deg ?? row.heading_deg_true)
          });
        });
        const nextOffset = Number(chunk.next_offset);
        if (!Number.isFinite(nextOffset) || nextOffset <= offset || rows.length === 0) break;
        if (chunk.complete || chunk.done || rows.length < limit) break;
        offset = nextOffset;
      }
      return samples.sort((a, b) => a.t - b.t);
    }

    async function load(leg) {
      const token = ++loadToken;
      reset(false);
      ensureMap();
      window.setTimeout(() => {
        if (map) map.invalidateSize();
      }, 60);

      const recordingUid = String((leg && leg.recording_uid) || '').trim();
      if (openLink) {
        if (recordingUid) {
          openLink.hidden = false;
          openLink.href = '/admin/cockpit_recorder_replay.php?id=' + encodeURIComponent(recordingUid);
        } else {
          openLink.hidden = true;
          openLink.removeAttribute('href');
        }
      }

      if (!recordingUid) {
        setStatus('No CVR recording is linked to this Flight Record, so GPS track and traffic are unavailable.', 'muted');
        return;
      }
      if (typeof L === 'undefined') {
        setStatus('Map library failed to load.', 'error');
        return;
      }

      setStatus('Loading GPS track and traffic…', 'loading');
      try {
        const manifest = await fetchJson(
          '/api/recordings/replay.php?id=' + encodeURIComponent(recordingUid)
            + '&version=2&manifest=1&compact=1&sample_stride=5&limit=8000'
        );
        if (token !== loadToken) return;

        duration = Math.max(
          0,
          finite(manifest.recording && manifest.recording.duration) || 0
        );
        let aircraft = Array.isArray(manifest.trafficAircraft) ? manifest.trafficAircraft : [];
        if (!aircraft.length && Array.isArray(manifest.traffic)) {
          aircraft = groupLegacyTraffic(manifest.traffic);
        }
        trafficAircraft = aircraft.map((item) => ({
          id: String(item.id || item.hex || '').toLowerCase(),
          callsign: String(item.callsign || item.cs || item.id || '').toUpperCase(),
          samples: Array.isArray(item.samples) ? item.samples : []
        }));

        ownshipSamples = await loadSamples(recordingUid, 5);
        if (token !== loadToken) return;

        if (!ownshipSamples.length) {
          setStatus('Recording is linked, but no GPS track samples are available yet.', 'muted');
          return;
        }

        if (!(duration > 0)) {
          duration = Number(ownshipSamples[ownshipSamples.length - 1].t) || 0;
        }
        timeline.min = '0';
        timeline.max = String(Math.max(1, duration));
        timeline.step = '0.1';
        timeline.value = '0';
        timeline.disabled = false;
        playBtn.disabled = false;
        if (endEl) endEl.textContent = formatClock(duration);
        drawStaticTrack();
        setTime(0);
        window.setTimeout(() => {
          if (map) map.invalidateSize();
        }, 120);
      } catch (error) {
        if (token !== loadToken) return;
        setStatus(error instanceof Error ? error.message : 'Could not load track data.', 'error');
      }
    }

    /**
     * Load a prebuilt ownship track (e.g. ADS-B history for schedule dispatched modal).
     * @param {Array<{t:number,lat:number,lon:number}>} samples
     * @param {{statusOk?:string,label?:string,style?:string,nearby?:Array,emptyMessage?:string}|undefined} options
     */
    function loadOwnshipSamples(samples, options) {
      const token = ++loadToken;
      options = options || {};
      reset(false);
      if (openLink) {
        openLink.hidden = true;
        openLink.removeAttribute('href');
      }
      ensureMap();
      applyVisualStyle(options.style || 'adsb');
      ownshipLabel = String(options.label || 'Aircraft').toUpperCase();
      liveNearby = Array.isArray(options.nearby) ? options.nearby : [];
      window.setTimeout(() => {
        if (map) map.invalidateSize();
      }, 40);

      if (typeof L === 'undefined') {
        setStatus('Map library failed to load.', 'error');
        return;
      }

      ownshipSamples = (Array.isArray(samples) ? samples : [])
        .map((sample) => ({
          t: finite(sample && sample.t),
          lat: finite(sample && sample.lat),
          lon: finite(sample && sample.lon),
          altitude_ft: finite(sample && (sample.altitude_ft ?? sample.alt)),
          altBaroFt: finite(sample && (sample.altitude_ft ?? sample.alt)),
          groundspeed_kt: finite(sample && (sample.groundspeed_kt ?? sample.gs)),
          gs: finite(sample && (sample.groundspeed_kt ?? sample.gs)),
          heading_deg: finite(sample && (sample.track_deg ?? sample.heading_deg ?? sample.trk)),
          track_deg: finite(sample && (sample.track_deg ?? sample.heading_deg ?? sample.trk)),
          trackTrueDeg: finite(sample && (sample.track_deg ?? sample.heading_deg ?? sample.trk))
        }))
        .filter((sample) => sample.t !== null && sample.lat !== null && sample.lon !== null)
        .sort((a, b) => a.t - b.t);

      trafficAircraft = [];
      if (!ownshipSamples.length) {
        // Still show live nearby / tip if available.
        if (liveNearby.length && map) {
          const pts = liveNearby
            .map((row) => [finite(row.lat), finite(row.lon)])
            .filter((pair) => pair[0] !== null && pair[1] !== null);
          if (pts.length) {
            map.fitBounds(L.latLngBounds(pts).pad(0.25));
            renderFrame();
          }
        }
        setStatus(options.emptyMessage || 'No ADS-B track samples are available for this window.', 'muted');
        return;
      }

      duration = Math.max(
        0,
        Number(ownshipSamples[ownshipSamples.length - 1].t) || 0
      );
      timeline.min = '0';
      timeline.max = String(Math.max(1, duration));
      timeline.step = '0.1';
      timeline.value = '0';
      timeline.disabled = false;
      playBtn.disabled = false;
      if (endEl) endEl.textContent = formatClock(duration);
      drawStaticTrack();
      setTime(duration > 0 ? duration : 0);
      setStatus(
        options.statusOk
          || (ownshipLabel + ' · ' + ownshipSamples.length.toLocaleString() + ' track samples'
            + (liveNearby.length ? (' · ' + liveNearby.length + ' nearby') : '')),
        'ok'
      );
      if (token !== loadToken) return;
      window.setTimeout(() => {
        if (map) map.invalidateSize();
      }, 120);
    }

    function reset(clearStatus) {
      stopLiveFollow();
      stopPlayback();
      ownshipSamples = [];
      trafficAircraft = [];
      liveNearby = [];
      liveTracks = Object.create(null);
      verticalProfile = null;
      replayMode = false;
      archivedTrafficKeys = Object.create(null);
      trafficHistoryLoaded = false;
      clearLiveMarkers();
      lastLiveTraceSignature = '';
      setFollowCentered(true);
      duration = 0;
      currentT = 0;
      timeline.value = '0';
      timeline.disabled = true;
      playBtn.disabled = true;
      playBtn.textContent = 'Play';
      if (currentEl) currentEl.textContent = '00:00';
      if (endEl) endEl.textContent = '00:00';
      if (trackLayer) trackLayer.clearLayers();
      if (dynamicLayer) dynamicLayer.clearLayers();
      if (profileRoot) profileRoot.hidden = true;
      if (profileSvg) profileSvg.innerHTML = '';
      if (profileMeta) profileMeta.textContent = 'Altitude · terrain clearance';
      if (centerBtn) centerBtn.hidden = true;
      if (ringsBtn) ringsBtn.hidden = true;
      clearRangeRings();
      lastRangeRingLat = null;
      lastRangeRingLon = null;
      if (clearStatus !== false) setStatus('Open a Flight Record with a linked CVR recording to preview GPS and traffic.', 'muted');
    }

    function invalidate() {
      if (map) {
        window.setTimeout(() => map.invalidateSize(), 40);
      }
    }

    playBtn.addEventListener('click', () => {
      if (playing) {
        stopPlayback();
        return;
      }
      if (!ownshipSamples.length || duration <= 0) return;
      if (liveFollowActive && !replayMode) {
        enterReplayMode(0);
      }
      startPlayback();
    });
    timeline.addEventListener('input', () => {
      stopPlayback();
      if (liveFollowActive && !replayMode) {
        enterReplayMode(Number(timeline.value || 0));
        return;
      }
      setTime(Number(timeline.value || 0), true);
    });
    if (centerBtn) {
      centerBtn.hidden = true;
      centerBtn.addEventListener('click', () => {
        exitReplayModeToLive();
      });
    }
    if (ringsBtn) {
      ringsBtn.hidden = true;
      ringsBtn.addEventListener('click', () => {
        setRangeRingsEnabled(!rangeRingsEnabled);
      });
    }

    reset();
    return {
      load,
      loadOwnshipSamples,
      startLiveFollow,
      stopLiveFollow,
      ingestLiveSnapshot,
      reset,
      invalidate,
      stopPlayback
    };
  }

  global.IPCALegTrackChart = {
    create: createController
  };
})(window);
