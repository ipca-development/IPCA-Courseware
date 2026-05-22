<?php
declare(strict_types=1);
/**
 * ALSIM AL172 AI Instructor — Standalone Proof of Concept
 *
 * Read-only telemetry via ALSIM datastore WebSocket.
 * Deterministic instructor logic (OpenAI integration planned for a later phase).
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ALSIM AI Instructor POC — IPCA.training</title>
<style>
  :root {
    --ipca-blue-900: #0f2d5c;
    --ipca-blue-700: #1e4a8a;
    --ipca-blue-500: #2563eb;
    --ipca-blue-100: #dbeafe;
    --ipca-green: #15803d;
    --ipca-green-bg: #dcfce7;
    --ipca-red: #b91c1c;
    --ipca-red-bg: #fee2e2;
    --ipca-amber: #b45309;
    --ipca-amber-bg: #fef3c7;
    --ipca-gray-50: #f8fafc;
    --ipca-gray-100: #f1f5f9;
    --ipca-gray-200: #e2e8f0;
    --ipca-gray-500: #64748b;
    --ipca-gray-700: #334155;
    --ipca-gray-900: #0f172a;
    --radius: 14px;
    --shadow: 0 10px 30px rgba(15, 45, 92, 0.08);
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: var(--ipca-gray-50);
    color: var(--ipca-gray-900);
    line-height: 1.5;
  }

  .page {
    max-width: 1180px;
    margin: 0 auto;
    padding: 24px 20px 48px;
  }

  /* Hero banner */
  .hero {
    background: linear-gradient(135deg, var(--ipca-blue-900) 0%, var(--ipca-blue-700) 55%, var(--ipca-blue-500) 100%);
    border-radius: calc(var(--radius) + 4px);
    padding: 28px 32px;
    color: #fff;
    box-shadow: var(--shadow);
  }

  .hero-kicker {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.72);
  }

  .hero h1 {
    margin: 8px 0 0;
    font-size: 32px;
    line-height: 1.1;
    letter-spacing: -0.03em;
    font-weight: 800;
  }

  .hero-sub {
    margin-top: 10px;
    font-size: 15px;
    color: rgba(255, 255, 255, 0.88);
    max-width: 720px;
  }

  .hero-badges {
    margin-top: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }

  .pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.02em;
    border: 1px solid transparent;
  }

  .pill-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .pill--ok {
    background: rgba(220, 252, 231, 0.18);
    color: #bbf7d0;
    border-color: rgba(187, 247, 208, 0.45);
  }
  .pill--ok .pill-dot { background: #4ade80; }

  .pill--bad {
    background: rgba(254, 226, 226, 0.16);
    color: #fecaca;
    border-color: rgba(252, 165, 165, 0.45);
  }
  .pill--bad .pill-dot { background: #f87171; }

  .pill--warn {
    background: rgba(254, 243, 199, 0.16);
    color: #fde68a;
    border-color: rgba(253, 230, 138, 0.4);
  }
  .pill--warn .pill-dot { background: #fbbf24; }

  /* Toolbar */
  .toolbar {
    margin-top: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }

  .btn {
    appearance: none;
    border: 1px solid var(--ipca-gray-200);
    background: #fff;
    color: var(--ipca-gray-900);
    border-radius: 10px;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, transform 0.1s;
  }

  .btn:hover { background: var(--ipca-gray-100); }
  .btn:active { transform: translateY(1px); }
  .btn:disabled { opacity: 0.45; cursor: not-allowed; }

  .btn--primary {
    background: var(--ipca-blue-500);
    border-color: var(--ipca-blue-500);
    color: #fff;
  }
  .btn--primary:hover { background: var(--ipca-blue-700); border-color: var(--ipca-blue-700); }

  .btn--danger {
    background: var(--ipca-red);
    border-color: var(--ipca-red);
    color: #fff;
  }
  .btn--danger:hover { background: #991b1b; border-color: #991b1b; }

  .btn--voice-on {
    background: var(--ipca-green);
    border-color: var(--ipca-green);
    color: #fff;
  }

  /* Grid */
  .grid {
    margin-top: 22px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
  }

  @media (max-width: 980px) {
    .grid { grid-template-columns: 1fr; }
  }

  .card {
    background: #fff;
    border: 1px solid var(--ipca-gray-200);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
  }

  .card-head {
    padding: 16px 20px 12px;
    border-bottom: 1px solid var(--ipca-gray-100);
  }

  .card-head h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  .card-body { padding: 16px 20px 20px; }

  /* Telemetry */
  .telemetry-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .telemetry-item {
    background: var(--ipca-gray-50);
    border: 1px solid var(--ipca-gray-200);
    border-radius: 10px;
    padding: 12px 14px;
  }

  .telemetry-item--wide { grid-column: 1 / -1; }

  .telemetry-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
    color: var(--ipca-gray-500);
  }

  .telemetry-value {
    margin-top: 4px;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
  }

  .telemetry-unit {
    font-size: 13px;
    font-weight: 600;
    color: var(--ipca-gray-500);
    margin-left: 4px;
  }

  /* Training goal */
  .goal-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .goal-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: var(--ipca-blue-100);
    border-radius: 10px;
    font-size: 14px;
  }

  .goal-list strong { font-weight: 800; }

  /* Instructor */
  .instructor-msg {
    background: linear-gradient(180deg, #f0f7ff 0%, #fff 100%);
    border: 2px solid var(--ipca-blue-500);
    border-radius: 12px;
    padding: 20px 22px;
    min-height: 110px;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.45;
    color: var(--ipca-blue-900);
  }

  .instructor-msg--idle {
    border-color: var(--ipca-gray-200);
    background: var(--ipca-gray-50);
    color: var(--ipca-gray-500);
    font-weight: 500;
    font-size: 15px;
  }

  .last-spoken {
    margin-top: 14px;
    font-size: 13px;
    color: var(--ipca-gray-700);
  }

  .last-spoken span {
    display: block;
    margin-top: 4px;
    font-weight: 600;
    color: var(--ipca-gray-900);
  }

  .event-log {
    margin-top: 16px;
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid var(--ipca-gray-200);
    border-radius: 10px;
    background: var(--ipca-gray-50);
  }

  .event-log-entry {
    padding: 10px 12px;
    border-bottom: 1px solid var(--ipca-gray-200);
    font-size: 12px;
    line-height: 1.45;
  }

  .event-log-entry:last-child { border-bottom: none; }

  .event-log-time {
    font-weight: 700;
    color: var(--ipca-blue-700);
    font-variant-numeric: tabular-nums;
  }

  .event-log-meta {
    color: var(--ipca-gray-500);
    margin-top: 2px;
  }

  .status-line {
    margin-top: 14px;
    font-size: 12px;
    color: var(--ipca-gray-500);
  }
</style>
</head>
<body>
<div class="page">

  <header class="hero">
    <div class="hero-kicker">IPCA.training · ALSIM AL172</div>
    <h1>ALSIM AI Instructor POC</h1>
    <p class="hero-sub">Straight and Level Training — 2000 ft / Heading 120</p>
    <div class="hero-badges">
      <span id="badgeConnection" class="pill pill--bad">
        <span class="pill-dot"></span> Disconnected
      </span>
      <span id="badgeScenario" class="pill pill--bad">
        <span class="pill-dot"></span> Stopped
      </span>
    </div>
  </header>

  <div class="toolbar">
    <button type="button" class="btn btn--primary" id="btnConnect" onclick="connectAlsim()">Connect</button>
    <button type="button" class="btn" id="btnStart" onclick="startScenario()" disabled>Start Scenario</button>
    <button type="button" class="btn btn--danger" id="btnStop" onclick="stopScenario()" disabled>Stop Scenario</button>
    <button type="button" class="btn" id="btnReset" onclick="resetInstructorState()">Reset Instructor State</button>
    <button type="button" class="btn" id="btnVoice" onclick="toggleVoice()">Enable Voice OFF</button>
  </div>

  <div class="grid">

    <section class="card">
      <div class="card-head"><h2>Live Telemetry</h2></div>
      <div class="card-body">
        <div class="telemetry-grid">
          <div class="telemetry-item">
            <div class="telemetry-label">Altitude</div>
            <div class="telemetry-value" id="telAltitude">—<span class="telemetry-unit">ft</span></div>
          </div>
          <div class="telemetry-item">
            <div class="telemetry-label">Heading</div>
            <div class="telemetry-value" id="telHeading">—<span class="telemetry-unit">°</span></div>
          </div>
          <div class="telemetry-item">
            <div class="telemetry-label">Airspeed</div>
            <div class="telemetry-value" id="telAirspeed">—<span class="telemetry-unit">kt</span></div>
          </div>
          <div class="telemetry-item">
            <div class="telemetry-label">Pitch</div>
            <div class="telemetry-value" id="telPitch">—<span class="telemetry-unit">°</span></div>
          </div>
          <div class="telemetry-item">
            <div class="telemetry-label">Bank</div>
            <div class="telemetry-value" id="telBank">—<span class="telemetry-unit">°</span></div>
          </div>
          <div class="telemetry-item telemetry-item--wide">
            <div class="telemetry-label">Latitude / Longitude</div>
            <div class="telemetry-value" id="telLatLon" style="font-size:16px;">— / —</div>
          </div>
        </div>
        <p class="status-line" id="wsStatus">WebSocket: not connected</p>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Training Goal</h2></div>
      <div class="card-body">
        <ul class="goal-list">
          <li><span>Target altitude</span><strong>2000 ft</strong></li>
          <li><span>Altitude tolerance</span><strong>±50 ft</strong></li>
          <li><span>Target heading</span><strong>120°</strong></li>
          <li><span>Heading tolerance</span><strong>±10°</strong></li>
        </ul>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>AI Instructor</h2></div>
      <div class="card-body">
        <div id="instructorMessage" class="instructor-msg instructor-msg--idle">
          Connect to ALSIM and start the scenario to begin coaching.
        </div>
        <div class="last-spoken">
          Last spoken message
          <span id="lastSpoken">—</span>
        </div>
        <div class="event-log" id="eventLog" aria-label="Instructor event log"></div>
      </div>
    </section>

  </div>
</div>

<script>
(function () {
  'use strict';

  // ── Scenario targets ──────────────────────────────────────────────────────
  const TARGET_ALTITUDE = 2000;
  const ALT_TOLERANCE = 50;
  const ALT_LEVEL_OFF = 15;
  const ALT_HIGH_WARN = 2050;
  const ALT_LOW_WARN = 1950;
  const TARGET_HEADING = 120;
  const HDG_TOLERANCE = 10;
  const HDG_ROLLOUT = 3;
  const STABLE_DURATION_MS = 15000;
  const EVAL_INTERVAL_MS = 500;
  const HISTORY_WINDOW_MS = 5000;
  const TREND_LOOKBACK_MS = 3000;
  const MESSAGE_COOLDOWN_MS = 4000;
  const SAME_MESSAGE_COOLDOWN_MS = 12000;
  const ALT_TREND_THRESHOLD = 3;
  const HDG_TREND_THRESHOLD = 1.5;

  const ALSIM_WS_URL = 'ws://192.168.0.31:15380/';
  const ALSIM_WS_PROTOCOL = 'datastore';

  const TELEMETRY_KEYS = [
    'd_FM_ktAircraftTrueAirspeed',
    'd_FM_ftAircraftTrueAltitude',
    'd_FM_degAircraftTrueHeading',
    'd_FM_degAircraftAttitude',
    'd_FM_degAircraftBank',
    'd_FM_mnAircraftLatitude',
    'd_FM_mnAircraftLongitude'
  ];

  // ── Application state ─────────────────────────────────────────────────────
  let ws = null;
  let scenarioRunning = false;
  let voiceEnabled = false;
  let evaluateTimer = null;

  const telemetry = {};
  TELEMETRY_KEYS.forEach(function (key) { telemetry[key] = null; });

  const history = [];

  // Instructor state — replace evaluateScenario() body with OpenAI later
  const instructorState = {
    altitudeCorrection: null,
    headingCorrection: false,
    headingContinueAnnounced: false,
    altitudeStableSince: null,
    headingStableSince: null,
    altitudeStableAnnounced: false,
    headingStableAnnounced: false,
    altitudeLevelOffAnnounced: false,
    altitudeContinueAnnounced: false
  };

  const spokenCooldowns = {};
  let lastSpokenAt = 0;
  let lastInstructorText = '';

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const el = {
    badgeConnection: document.getElementById('badgeConnection'),
    badgeScenario: document.getElementById('badgeScenario'),
    btnConnect: document.getElementById('btnConnect'),
    btnStart: document.getElementById('btnStart'),
    btnStop: document.getElementById('btnStop'),
    btnVoice: document.getElementById('btnVoice'),
    wsStatus: document.getElementById('wsStatus'),
    telAltitude: document.getElementById('telAltitude'),
    telHeading: document.getElementById('telHeading'),
    telAirspeed: document.getElementById('telAirspeed'),
    telPitch: document.getElementById('telPitch'),
    telBank: document.getElementById('telBank'),
    telLatLon: document.getElementById('telLatLon'),
    instructorMessage: document.getElementById('instructorMessage'),
    lastSpoken: document.getElementById('lastSpoken'),
    eventLog: document.getElementById('eventLog')
  };

  // ── Utility ───────────────────────────────────────────────────────────────
  function fmtNum(value, decimals) {
    if (value === null || value === undefined || Number.isNaN(value)) {
      return '—';
    }
    return Number(value).toFixed(decimals);
  }

  function nowMs() {
    return Date.now();
  }

  function formatTime(ts) {
    const d = new Date(ts);
    return d.toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function setBadge(node, ok, okText, badText) {
    node.className = 'pill ' + (ok ? 'pill--ok' : 'pill--bad');
    node.innerHTML = '<span class="pill-dot"></span> ' + (ok ? okText : badText);
  }

  function setScenarioBadge(running) {
    if (running) {
      el.badgeScenario.className = 'pill pill--ok';
      el.badgeScenario.innerHTML = '<span class="pill-dot"></span> Running';
    } else {
      el.badgeScenario.className = 'pill pill--warn';
      el.badgeScenario.innerHTML = '<span class="pill-dot"></span> Stopped';
    }
  }

  function getAltitude() {
    return telemetry['d_FM_ftAircraftTrueAltitude'];
  }

  function getHeading() {
    return telemetry['d_FM_degAircraftTrueHeading'];
  }

  // ── Heading normalization ─────────────────────────────────────────────────
  function normalizeHeadingDiff(current, target) {
    let diff = current - target;
    while (diff > 180) diff -= 360;
    while (diff < -180) diff += 360;
    return diff;
  }

  // ── Trend helpers ─────────────────────────────────────────────────────────
  function pruneHistory() {
    const cutoff = nowMs() - HISTORY_WINDOW_MS;
    while (history.length > 0 && history[0].ts < cutoff) {
      history.shift();
    }
  }

  function recordHistorySample() {
    const alt = getAltitude();
    const hdg = getHeading();
    if (alt === null || hdg === null) return;

    history.push({ ts: nowMs(), altitude: alt, heading: hdg });
    pruneHistory();
  }

  function sampleAtLookback(field) {
    const targetTs = nowMs() - TREND_LOOKBACK_MS;
    let best = null;
    let bestDelta = Infinity;

    for (let i = 0; i < history.length; i++) {
      const entry = history[i];
      const delta = Math.abs(entry.ts - targetTs);
      if (delta < bestDelta) {
        bestDelta = delta;
        best = entry;
      }
    }

    if (!best || bestDelta > 1200) return null;
    return best[field];
  }

  function getAltitudeTrend() {
    const current = getAltitude();
    const past = sampleAtLookback('altitude');
    if (current === null || past === null) return 0;
    return current - past;
  }

  function getHeadingDiffTrend() {
    const current = getHeading();
    const pastHdg = sampleAtLookback('heading');
    if (current === null || pastHdg === null) return 0;

    const currentDiff = normalizeHeadingDiff(current, TARGET_HEADING);
    const pastDiff = normalizeHeadingDiff(pastHdg, TARGET_HEADING);
    return currentDiff - pastDiff;
  }

  function isAltitudeClimbing() {
    return getAltitudeTrend() > ALT_TREND_THRESHOLD;
  }

  function isAltitudeDescending() {
    return getAltitudeTrend() < -ALT_TREND_THRESHOLD;
  }

  function isHeadingMovingTowardTarget() {
    const diff = normalizeHeadingDiff(getHeading(), TARGET_HEADING);
    const diffTrend = getHeadingDiffTrend();
    if (Math.abs(diff) < HDG_ROLLOUT) return false;
    if (diff > 0 && diffTrend < -HDG_TREND_THRESHOLD) return true;
    if (diff < 0 && diffTrend > HDG_TREND_THRESHOLD) return true;
    return false;
  }

  function isHeadingTrendStabilizing() {
    return Math.abs(getHeadingDiffTrend()) <= HDG_TREND_THRESHOLD;
  }

  // ── WebSocket / ALSIM datastore ─────────────────────────────────────────
  function connectAlsim() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
      return;
    }

    el.wsStatus.textContent = 'WebSocket: connecting…';
    el.btnConnect.disabled = true;

    ws = new WebSocket(ALSIM_WS_URL, ALSIM_WS_PROTOCOL);

    ws.onopen = function () {
      setBadge(el.badgeConnection, true, 'Connected', 'Disconnected');
      el.wsStatus.textContent = 'WebSocket: connected — subscribing telemetry';
      el.btnConnect.textContent = 'Connected';
      el.btnStart.disabled = false;
      subscribeTelemetry();
    };

    ws.onmessage = function (event) {
      handleDatastoreMessage(event.data);
    };

    ws.onerror = function () {
      el.wsStatus.textContent = 'WebSocket: connection error';
    };

    ws.onclose = function () {
      setBadge(el.badgeConnection, false, 'Connected', 'Disconnected');
      el.wsStatus.textContent = 'WebSocket: disconnected';
      el.btnConnect.disabled = false;
      el.btnConnect.textContent = 'Connect';
      el.btnStart.disabled = true;
      stopScenario();
      ws = null;
    };
  }

  function subscribeTelemetry() {
    TELEMETRY_KEYS.forEach(function (keyName, index) {
      const payload = {
        action: 'addWatch',
        keyType: 0,
        keyName: keyName,
        msgID: index + 1
      };
      ws.send(JSON.stringify(payload));
    });
    el.wsStatus.textContent = 'WebSocket: subscribed to ' + TELEMETRY_KEYS.length + ' telemetry keys';
  }

  function handleDatastoreMessage(raw) {
    let msg;
    try {
      msg = JSON.parse(raw);
    } catch (e) {
      return;
    }

    if (msg.action !== 'update' || !Array.isArray(msg.double)) {
      return;
    }

    msg.double.forEach(function (item) {
      if (!item || typeof item.key !== 'string') return;
      const parsed = parseFloat(item.value);
      telemetry[item.key] = Number.isFinite(parsed) ? parsed : null;
    });

    recordHistorySample();
    updateTelemetryUI();
  }

  function updateTelemetryUI() {
    const alt = getAltitude();
    const hdg = getHeading();
    const ias = telemetry['d_FM_ktAircraftTrueAirspeed'];
    const pitch = telemetry['d_FM_degAircraftAttitude'];
    const bank = telemetry['d_FM_degAircraftBank'];
    const lat = telemetry['d_FM_mnAircraftLatitude'];
    const lon = telemetry['d_FM_mnAircraftLongitude'];

    el.telAltitude.innerHTML = fmtNum(alt, 0) + '<span class="telemetry-unit">ft</span>';
    el.telHeading.innerHTML = fmtNum(hdg, 0) + '<span class="telemetry-unit">°</span>';
    el.telAirspeed.innerHTML = fmtNum(ias, 0) + '<span class="telemetry-unit">kt</span>';
    el.telPitch.innerHTML = fmtNum(pitch, 1) + '<span class="telemetry-unit">°</span>';
    el.telBank.innerHTML = fmtNum(bank, 1) + '<span class="telemetry-unit">°</span>';
    el.telLatLon.textContent = fmtNum(lat, 5) + ' / ' + fmtNum(lon, 5);
  }

  // ── Scenario control ──────────────────────────────────────────────────────
  function startScenario() {
    if (!ws || ws.readyState !== WebSocket.OPEN) return;
    scenarioRunning = true;
    setScenarioBadge(true);
    el.btnStart.disabled = true;
    el.btnStop.disabled = false;

    if (evaluateTimer) clearInterval(evaluateTimer);
    evaluateTimer = setInterval(evaluateScenario, EVAL_INTERVAL_MS);
  }

  function stopScenario() {
    scenarioRunning = false;
    setScenarioBadge(false);
    el.btnStart.disabled = !(ws && ws.readyState === WebSocket.OPEN);
    el.btnStop.disabled = true;

    if (evaluateTimer) {
      clearInterval(evaluateTimer);
      evaluateTimer = null;
    }

    window.speechSynthesis.cancel();
  }

  function resetInstructorState() {
    instructorState.altitudeCorrection = null;
    instructorState.headingCorrection = false;
    instructorState.altitudeStableSince = null;
    instructorState.headingStableSince = null;
    instructorState.altitudeStableAnnounced = false;
    instructorState.headingStableAnnounced = false;
    instructorState.altitudeLevelOffAnnounced = false;
    instructorState.altitudeContinueAnnounced = false;
    instructorState.headingContinueAnnounced = false;

    Object.keys(spokenCooldowns).forEach(function (k) { delete spokenCooldowns[k]; });
    lastSpokenAt = 0;

    el.instructorMessage.textContent = 'Instructor state reset. Start the scenario to resume coaching.';
    el.instructorMessage.className = 'instructor-msg instructor-msg--idle';
  }

  function toggleVoice() {
    voiceEnabled = !voiceEnabled;
    el.btnVoice.textContent = voiceEnabled ? 'Enable Voice ON' : 'Enable Voice OFF';
    el.btnVoice.className = voiceEnabled ? 'btn btn--voice-on' : 'btn';
    if (!voiceEnabled) {
      window.speechSynthesis.cancel();
    }
  }

  // ── Instructor output ─────────────────────────────────────────────────────
  function canSpeakMessage(text) {
    const t = nowMs();
    if (t - lastSpokenAt < MESSAGE_COOLDOWN_MS) return false;
    if (spokenCooldowns[text] && t - spokenCooldowns[text] < SAME_MESSAGE_COOLDOWN_MS) return false;
    return true;
  }

  function speakInstructorMessage(text) {
    if (!scenarioRunning) return;
    if (!canSpeakMessage(text)) return;

    lastSpokenAt = nowMs();
    spokenCooldowns[text] = lastSpokenAt;
    lastInstructorText = text;

    el.instructorMessage.textContent = text;
    el.instructorMessage.className = 'instructor-msg';
    el.lastSpoken.textContent = text;

    logInstructorEvent(text);

    if (voiceEnabled && 'speechSynthesis' in window) {
      window.speechSynthesis.cancel();
      const utter = new SpeechSynthesisUtterance(text);
      utter.rate = 0.95;
      utter.pitch = 1;
      window.speechSynthesis.speak(utter);
    }
  }

  function logInstructorEvent(message) {
    const alt = getAltitude();
    const hdg = getHeading();
    const entry = document.createElement('div');
    entry.className = 'event-log-entry';
    entry.innerHTML =
      '<div class="event-log-time">' + formatTime(nowMs()) + '</div>' +
      '<div>' + message + '</div>' +
      '<div class="event-log-meta">ALT ' + fmtNum(alt, 0) + ' ft · HDG ' + fmtNum(hdg, 0) + '°</div>';
    el.eventLog.insertBefore(entry, el.eventLog.firstChild);
  }

  // ── Deterministic instructor logic ────────────────────────────────────────
  // TODO: Replace this function with OpenAI-generated coaching calls.
  function evaluateScenario() {
    if (!scenarioRunning) return;

    const alt = getAltitude();
    const hdg = getHeading();
    if (alt === null || hdg === null) return;

    const altDiff = alt - TARGET_ALTITUDE;
    const hdgDiff = normalizeHeadingDiff(hdg, TARGET_HEADING);
    const altTrend = getAltitudeTrend();
    const inAltTolerance = Math.abs(altDiff) <= ALT_TOLERANCE;
    const inHdgTolerance = Math.abs(hdgDiff) <= HDG_TOLERANCE;

    // Track continuous tolerance windows
    if (inAltTolerance) {
      if (instructorState.altitudeStableSince === null) {
        instructorState.altitudeStableSince = nowMs();
      }
    } else {
      instructorState.altitudeStableSince = null;
      instructorState.altitudeStableAnnounced = false;
    }

    if (inHdgTolerance) {
      if (instructorState.headingStableSince === null) {
        instructorState.headingStableSince = nowMs();
      }
    } else {
      instructorState.headingStableSince = null;
      instructorState.headingStableAnnounced = false;
    }

    // ── Altitude logic (priority) ───────────────────────────────────────────
    if (alt > ALT_HIGH_WARN && isAltitudeClimbing()) {
      instructorState.altitudeCorrection = 'high';
      instructorState.altitudeLevelOffAnnounced = false;
      instructorState.altitudeContinueAnnounced = false;
      speakInstructorMessage("Kay watch your altitude, let's go back to 2000 feet, bring your nose down 2 fingers.");
      return;
    }

    if (alt < ALT_LOW_WARN && isAltitudeDescending()) {
      instructorState.altitudeCorrection = 'low';
      instructorState.altitudeLevelOffAnnounced = false;
      instructorState.altitudeContinueAnnounced = false;
      speakInstructorMessage("Kay watch your altitude, let's go back to 2000 feet, bring your nose up 2 fingers.");
      return;
    }

    if (
      !instructorState.altitudeContinueAnnounced &&
      instructorState.altitudeCorrection === 'high' &&
      altTrend <= ALT_TREND_THRESHOLD
    ) {
      instructorState.altitudeContinueAnnounced = true;
      instructorState.altitudeCorrection = 'stabilizing';
      speakInstructorMessage('Good Kay, continue.');
      return;
    }

    if (
      !instructorState.altitudeContinueAnnounced &&
      instructorState.altitudeCorrection === 'low' &&
      altTrend >= -ALT_TREND_THRESHOLD
    ) {
      instructorState.altitudeContinueAnnounced = true;
      instructorState.altitudeCorrection = 'stabilizing';
      speakInstructorMessage('Good Kay, continue.');
      return;
    }

    if (
      instructorState.altitudeCorrection !== null &&
      !instructorState.altitudeLevelOffAnnounced &&
      Math.abs(altDiff) <= ALT_LEVEL_OFF
    ) {
      instructorState.altitudeLevelOffAnnounced = true;
      speakInstructorMessage('Ok Kay, level off now.');
      return;
    }

    if (
      inAltTolerance &&
      instructorState.altitudeStableSince !== null &&
      !instructorState.altitudeStableAnnounced &&
      nowMs() - instructorState.altitudeStableSince >= STABLE_DURATION_MS
    ) {
      instructorState.altitudeStableAnnounced = true;
      instructorState.altitudeCorrection = null;
      speakInstructorMessage('Good job Kay, keep going.');
      return;
    }

    // ── Heading logic (secondary) ───────────────────────────────────────────
    if (hdgDiff > HDG_TOLERANCE) {
      instructorState.headingCorrection = true;
      instructorState.headingContinueAnnounced = false;
      speakInstructorMessage('Kay watch your heading, gently turn left back to heading 120.');
      return;
    }

    if (hdgDiff < -HDG_TOLERANCE) {
      instructorState.headingCorrection = true;
      instructorState.headingContinueAnnounced = false;
      speakInstructorMessage('Kay watch your heading, gently turn right back to heading 120.');
      return;
    }

    if (
      instructorState.headingCorrection &&
      !instructorState.headingContinueAnnounced &&
      isHeadingMovingTowardTarget() &&
      isHeadingTrendStabilizing()
    ) {
      instructorState.headingContinueAnnounced = true;
      speakInstructorMessage('Good Kay, continue correcting.');
      return;
    }

    if (Math.abs(hdgDiff) <= HDG_ROLLOUT && instructorState.headingCorrection) {
      instructorState.headingCorrection = false;
      speakInstructorMessage('Ok Kay, roll out on heading 120.');
      return;
    }

    if (
      inHdgTolerance &&
      instructorState.headingStableSince !== null &&
      !instructorState.headingStableAnnounced &&
      nowMs() - instructorState.headingStableSince >= STABLE_DURATION_MS
    ) {
      instructorState.headingStableAnnounced = true;
      speakInstructorMessage('Good job Kay, heading is steady.');
    }
  }

  // Expose named functions for debugging / future OpenAI hook
  window.connectAlsim = connectAlsim;
  window.subscribeTelemetry = subscribeTelemetry;
  window.handleDatastoreMessage = handleDatastoreMessage;
  window.updateTelemetryUI = updateTelemetryUI;
  window.startScenario = startScenario;
  window.stopScenario = stopScenario;
  window.evaluateScenario = evaluateScenario;
  window.speakInstructorMessage = speakInstructorMessage;
  window.logInstructorEvent = logInstructorEvent;
  window.normalizeHeadingDiff = normalizeHeadingDiff;
  window.resetInstructorState = resetInstructorState;
  window.toggleVoice = toggleVoice;

  setScenarioBadge(false);
})();
</script>
</body>
</html>
