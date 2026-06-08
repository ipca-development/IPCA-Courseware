<?php
/**
 * Eye Focus Proof of Concept
 *
 * Standalone browser-based gaze/focus classifier using a USB webcam and
 * MediaPipe Face Landmarker. No server-side processing, no database, no IPCA integration.
 *
 * Host at: https://ipca.training/test/eye_focus_poc.php
 * Requires HTTPS or localhost for camera access.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Eye Focus Proof of Concept</title>
  <style>
    :root {
      --bg: #0a0e14;
      --panel: #121820;
      --panel-border: #1e2a38;
      --text: #c8d4e0;
      --text-dim: #6b7d8f;
      --accent: #3d8bfd;
      --inside: #2ecc71;
      --inside-glow: rgba(46, 204, 113, 0.35);
      --outside: #f39c12;
      --outside-glow: rgba(243, 156, 18, 0.35);
      --outside-blue: #3498db;
      --noface: #e74c3c;
      --noface-glow: rgba(231, 76, 60, 0.35);
      --mono: "SF Mono", "Fira Code", "Consolas", monospace;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      background-image:
        radial-gradient(ellipse at 20% 0%, rgba(61, 139, 253, 0.06) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 100%, rgba(46, 204, 113, 0.04) 0%, transparent 50%);
    }

    .page {
      max-width: 1280px;
      margin: 0 auto;
      padding: 1.5rem;
    }

    header {
      text-align: center;
      margin-bottom: 1.5rem;
    }

    header h1 {
      font-size: 1.5rem;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #e8eef4;
    }

    header .subtitle {
      margin-top: 0.35rem;
      font-size: 0.8rem;
      color: var(--text-dim);
    }

    .security-note {
      margin-top: 0.75rem;
      padding: 0.5rem 1rem;
      background: rgba(243, 156, 18, 0.1);
      border: 1px solid rgba(243, 156, 18, 0.25);
      border-radius: 6px;
      font-size: 0.78rem;
      color: #f5c87a;
      display: inline-block;
    }

    .controls-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 1.25rem;
      padding: 0.85rem 1rem;
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 8px;
    }

    .controls-bar label {
      font-size: 0.8rem;
      color: var(--text-dim);
      margin-right: 0.25rem;
    }

    select {
      background: #0d1218;
      color: var(--text);
      border: 1px solid var(--panel-border);
      border-radius: 5px;
      padding: 0.45rem 0.6rem;
      font-size: 0.85rem;
      min-width: 220px;
    }

    .btn {
      padding: 0.5rem 1rem;
      border: 1px solid var(--panel-border);
      border-radius: 5px;
      background: #1a2330;
      color: var(--text);
      font-size: 0.85rem;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }

    .btn:hover:not(:disabled) { background: #243040; border-color: #2e4058; }
    .btn:disabled { opacity: 0.45; cursor: not-allowed; }

    .btn-primary { background: #1a3a5c; border-color: #2a5a8c; }
    .btn-primary:hover:not(:disabled) { background: #224a74; }

    .btn-danger { background: #3a1a1a; border-color: #6a2a2a; }
    .btn-danger:hover:not(:disabled) { background: #4a2222; }

    .btn-calibrate { background: #1a3a2a; border-color: #2a6a4a; }
    .btn-calibrate:hover:not(:disabled) { background: #224a36; }

    .btn-voice-on { background: #2a3a1a; border-color: #4a6a2a; color: #b8e06a; }

    .main-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;
      margin-bottom: 1.25rem;
    }

    @media (max-width: 860px) {
      .main-grid { grid-template-columns: 1fr; }
    }

    .video-panel, .status-panel {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 10px;
      overflow: hidden;
    }

    .panel-header {
      padding: 0.6rem 1rem;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--text-dim);
      border-bottom: 1px solid var(--panel-border);
      background: rgba(0, 0, 0, 0.2);
    }

    .video-wrap {
      position: relative;
      background: #000;
      aspect-ratio: 16 / 9;
    }

    #webcam {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transform: scaleX(-1); /* mirror for natural self-view */
    }

    #overlay-canvas {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
      transform: scaleX(-1);
    }

    .status-panel {
      display: flex;
      flex-direction: column;
    }

    .status-indicator {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2rem 1.5rem;
      min-height: 280px;
      transition: background 0.4s, box-shadow 0.4s;
    }

    .status-indicator.inside {
      background: linear-gradient(160deg, rgba(46, 204, 113, 0.12) 0%, rgba(10, 14, 20, 0) 60%);
      box-shadow: inset 0 0 60px var(--inside-glow);
    }

    .status-indicator.outside {
      background: linear-gradient(160deg, rgba(52, 152, 219, 0.12) 0%, rgba(243, 156, 18, 0.08) 50%, rgba(10, 14, 20, 0) 80%);
      box-shadow: inset 0 0 60px var(--outside-glow);
    }

    .status-indicator.noface {
      background: linear-gradient(160deg, rgba(231, 76, 60, 0.1) 0%, rgba(10, 14, 20, 0) 60%);
      box-shadow: inset 0 0 60px var(--noface-glow);
    }

    .status-indicator.idle {
      background: rgba(0, 0, 0, 0.15);
      box-shadow: none;
    }

    .status-dot {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      margin-bottom: 1.25rem;
      transition: background 0.4s, box-shadow 0.4s;
    }

    .status-indicator.inside .status-dot {
      background: var(--inside);
      box-shadow: 0 0 30px var(--inside-glow), 0 0 60px var(--inside-glow);
    }

    .status-indicator.outside .status-dot {
      background: linear-gradient(135deg, var(--outside-blue), var(--outside));
      box-shadow: 0 0 30px var(--outside-glow), 0 0 60px var(--outside-glow);
    }

    .status-indicator.noface .status-dot {
      background: var(--noface);
      box-shadow: 0 0 30px var(--noface-glow);
    }

    .status-indicator.idle .status-dot {
      background: #2a3440;
      box-shadow: none;
    }

    .status-label {
      font-size: 1.6rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-align: center;
      line-height: 1.3;
    }

    .status-indicator.inside .status-label { color: var(--inside); }
    .status-indicator.outside .status-label { color: var(--outside); }
    .status-indicator.noface .status-label { color: var(--noface); }
    .status-indicator.idle .status-label { color: var(--text-dim); }

    .status-meta {
      padding: 1rem 1.25rem;
      border-top: 1px solid var(--panel-border);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.6rem 1rem;
      font-size: 0.82rem;
    }

    .meta-item label {
      display: block;
      color: var(--text-dim);
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 0.15rem;
    }

    .meta-item span {
      font-family: var(--mono);
      color: #dde6ef;
    }

    .debug-panel {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 8px;
      overflow: hidden;
    }

    .debug-panel summary {
      padding: 0.7rem 1rem;
      cursor: pointer;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-dim);
      user-select: none;
      list-style: none;
    }

    .debug-panel summary::-webkit-details-marker { display: none; }

    .debug-panel summary::before {
      content: "▸ ";
      display: inline-block;
      transition: transform 0.15s;
    }

    .debug-panel[open] summary::before { transform: rotate(90deg); }

    .debug-content {
      padding: 0.75rem 1rem 1rem;
      border-top: 1px solid var(--panel-border);
      font-family: var(--mono);
      font-size: 0.78rem;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 0.5rem 1.5rem;
    }

    .debug-row { color: var(--text-dim); }
    .debug-row strong { color: var(--text); font-weight: 500; }

    .error-banner {
      display: none;
      margin-bottom: 1rem;
      padding: 0.75rem 1rem;
      background: rgba(231, 76, 60, 0.12);
      border: 1px solid rgba(231, 76, 60, 0.3);
      border-radius: 6px;
      color: #f5a0a0;
      font-size: 0.85rem;
    }

    .error-banner.visible { display: block; }

    .loading-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, 0.7);
      font-size: 0.85rem;
      color: var(--text-dim);
    }

    .loading-overlay.hidden { display: none; }

    /* ---- Guided calibration overlay ---- */
    .calibration-overlay {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-end;
      padding-bottom: 2rem;
    }

    .calibration-overlay.hidden { display: none; }

    .calibration-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(4, 8, 14, 0.82);
      backdrop-filter: blur(2px);
    }

    .cal-dot {
      position: fixed;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: #3d8bfd;
      border: 3px solid #e8f2ff;
      box-shadow: 0 0 24px rgba(61, 139, 253, 0.9), 0 0 48px rgba(61, 139, 253, 0.45);
      transform: translate(-50%, -50%);
      animation: cal-pulse 1.1s ease-in-out infinite;
      z-index: 1002;
      pointer-events: none;
    }

    .cal-dot.pos-center      { top: 50%; left: 50%; }
    .cal-dot.pos-top-left    { top: var(--cal-margin); left: var(--cal-margin); }
    .cal-dot.pos-top-right   { top: var(--cal-margin); right: var(--cal-margin); left: auto; transform: translate(50%, -50%); }
    .cal-dot.pos-bottom-left { bottom: var(--cal-margin); left: var(--cal-margin); top: auto; transform: translate(-50%, 50%); }
    .cal-dot.pos-bottom-right{ bottom: var(--cal-margin); right: var(--cal-margin); top: auto; left: auto; transform: translate(50%, 50%); }

    @keyframes cal-pulse {
      0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
      50%      { transform: translate(-50%, -50%) scale(1.18); opacity: 0.85; }
    }

    .cal-dot.pos-top-right,
    .cal-dot.pos-bottom-right {
      animation-name: cal-pulse-right;
    }

    .cal-dot.pos-bottom-left,
    .cal-dot.pos-bottom-right {
      animation-name: cal-pulse-bottom;
    }

    @keyframes cal-pulse-right {
      0%, 100% { transform: translate(50%, -50%) scale(1); opacity: 1; }
      50%      { transform: translate(50%, -50%) scale(1.18); opacity: 0.85; }
    }

    @keyframes cal-pulse-bottom {
      0%, 100% { transform: translate(-50%, 50%) scale(1); opacity: 1; }
      50%      { transform: translate(-50%, 50%) scale(1.18); opacity: 0.85; }
    }

    .cal-dot.pos-bottom-right { animation-name: cal-pulse-bottom-right; }

    @keyframes cal-pulse-bottom-right {
      0%, 100% { transform: translate(50%, 50%) scale(1); opacity: 1; }
      50%      { transform: translate(50%, 50%) scale(1.18); opacity: 0.85; }
    }

    .calibration-panel {
      position: relative;
      z-index: 1003;
      width: min(520px, calc(100vw - 2rem));
      padding: 1.25rem 1.5rem;
      background: rgba(18, 24, 32, 0.95);
      border: 1px solid var(--panel-border);
      border-radius: 12px;
      text-align: center;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
    }

    .calibration-panel h2 {
      font-size: 1rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #e8eef4;
      margin-bottom: 0.6rem;
    }

    .calibration-panel .cal-step-label {
      font-size: 0.75rem;
      color: var(--accent);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 0.5rem;
    }

    .calibration-panel .cal-instruction {
      font-size: 0.95rem;
      line-height: 1.45;
      color: var(--text);
      margin-bottom: 1rem;
    }

    .cal-progress-track {
      height: 6px;
      background: #1a2330;
      border-radius: 3px;
      overflow: hidden;
      margin-bottom: 0.65rem;
    }

    .cal-progress-fill {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, var(--accent), var(--inside));
      border-radius: 3px;
      transition: width 0.1s linear;
    }

    .cal-countdown {
      font-family: var(--mono);
      font-size: 0.82rem;
      color: var(--text-dim);
      margin-bottom: 1rem;
    }

    .calibration-actions {
      display: flex;
      justify-content: center;
      gap: 0.75rem;
    }

    .meta-calibrated {
      color: var(--inside) !important;
    }

    .meta-not-calibrated {
      color: var(--outside) !important;
    }
  </style>
</head>
<body>
  <div class="page">
    <header>
      <h1>Eye Focus Proof of Concept</h1>
      <p class="subtitle">USB webcam + MediaPipe face landmarks &mdash; local browser processing only</p>
      <p class="security-note">
        Camera access requires <strong>HTTPS</strong> or <strong>localhost</strong>.
        All video stays on your device; nothing is uploaded.
      </p>
    </header>

    <div id="error-banner" class="error-banner" role="alert"></div>

    <div class="controls-bar">
      <div>
        <label for="camera-select">Camera</label>
        <select id="camera-select" disabled>
          <option value="">— Select camera —</option>
        </select>
      </div>
      <button id="btn-start" class="btn btn-primary" disabled>Start Camera</button>
      <button id="btn-stop" class="btn btn-danger" disabled>Stop Camera</button>
      <button id="btn-calibrate" class="btn btn-calibrate" disabled>Start Calibration</button>
      <button id="btn-voice" class="btn">Enable Voice</button>
    </div>

    <div class="main-grid">
      <div class="video-panel">
        <div class="panel-header">Live Feed</div>
        <div class="video-wrap">
          <video id="webcam" autoplay playsinline muted></video>
          <canvas id="overlay-canvas"></canvas>
          <div id="loading-overlay" class="loading-overlay">Loading MediaPipe model&hellip;</div>
        </div>
      </div>

      <div class="status-panel">
        <div class="panel-header">Focus Status</div>
        <div id="status-indicator" class="status-indicator idle">
          <div class="status-dot"></div>
          <div id="status-label" class="status-label">CAMERA OFF</div>
        </div>
        <div class="status-meta">
          <div class="meta-item">
            <label>Confidence / Score</label>
            <span id="meta-score">—</span>
          </div>
          <div class="meta-item">
            <label>Face Detected</label>
            <span id="meta-face">—</span>
          </div>
          <div class="meta-item">
            <label>Calibration</label>
            <span id="meta-calibration" class="meta-not-calibrated">not done</span>
          </div>
          <div class="meta-item">
            <label>Selected Camera</label>
            <span id="meta-camera">—</span>
          </div>
        </div>
      </div>
    </div>

    <details class="debug-panel">
      <summary>Debug Panel</summary>
      <div class="debug-content">
        <div class="debug-row">Raw gaze H: <strong id="dbg-raw-h">—</strong></div>
        <div class="debug-row">Raw gaze V: <strong id="dbg-raw-v">—</strong></div>
        <div class="debug-row">Smoothed H: <strong id="dbg-smooth-h">—</strong></div>
        <div class="debug-row">Smoothed V: <strong id="dbg-smooth-v">—</strong></div>
        <div class="debug-row">Baseline H: <strong id="dbg-baseline-h">—</strong></div>
        <div class="debug-row">Baseline V: <strong id="dbg-baseline-v">—</strong></div>
        <div class="debug-row">Diff from baseline: <strong id="dbg-diff">—</strong></div>
        <div class="debug-row">Threshold H: <strong id="dbg-threshold-h">—</strong></div>
        <div class="debug-row">Threshold V: <strong id="dbg-threshold-v">—</strong></div>
        <div class="debug-row">Calibration: <strong id="dbg-calibration">not done</strong></div>
        <div class="debug-row">FPS estimate: <strong id="dbg-fps">—</strong></div>
        <div class="debug-row">Last status change: <strong id="dbg-status-change">—</strong></div>
        <div class="debug-row">Voice enabled: <strong id="dbg-voice">no</strong></div>
        <div class="debug-row">Voice cooldown: <strong id="dbg-cooldown">—</strong></div>
      </div>
    </details>
  </div>

  <!-- Full-screen guided calibration wizard -->
  <div id="calibration-overlay" class="calibration-overlay hidden" style="--cal-margin: 56px;">
    <div class="calibration-backdrop"></div>
    <div id="cal-dot" class="cal-dot pos-center"></div>
    <div class="calibration-panel">
      <h2>Eye Focus Calibration</h2>
      <p id="cal-step-label" class="cal-step-label">Step 1 of 5</p>
      <p id="cal-instruction" class="cal-instruction">Look at the dot on screen.</p>
      <div class="cal-progress-track">
        <div id="cal-progress-fill" class="cal-progress-fill"></div>
      </div>
      <p id="cal-countdown" class="cal-countdown">Preparing…</p>
      <div class="calibration-actions">
        <button id="btn-cal-cancel" class="btn">Cancel</button>
      </div>
    </div>
  </div>

  <script type="module">
    import { FaceLandmarker, FilesetResolver } from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/+esm";

    // =========================================================================
    // TUNING CONSTANTS — adjust these to change behaviour
    // =========================================================================

    /** Preferred camera resolution (falls back gracefully if unsupported). */
    const CAMERA_WIDTH  = 1280;
    const CAMERA_HEIGHT = 720;
    const CAMERA_FPS    = 30;

    /**
     * Fallback gaze threshold before calibration completes (0–1 scale).
     * After guided calibration, inside/outside uses learned margins instead.
     */
    const GAZE_THRESHOLD = 0.12;

    /**
     * Fraction of center-to-corner gaze range treated as "inside".
     * Lower = stricter (must look closer to center). Range: 0.3–0.55 typical.
     */
    const INSIDE_ZONE_FRACTION = 0.42;

    /** Milliseconds to hold gaze on each calibration dot. */
    const CALIBRATION_STEP_DURATION_MS = 3000;

    /** Minimum face-tracked samples required per calibration step. */
    const CALIBRATION_MIN_SAMPLES = 18;

    /** Distance of calibration dots from screen edges (matches --cal-margin CSS). */
    const CALIBRATION_DOT_MARGIN = 56;

    /**
     * Guided calibration steps — edit instructions or order here.
     * Center establishes "inside"; corners map "outside" gaze extremes.
     */
    const CALIBRATION_STEPS = [
      {
        id: "center",
        position: "pos-center",
        stepLabel: "Center (Inside)",
        instruction: "Look directly at the center dot. This defines your inside focus zone.",
      },
      {
        id: "topLeft",
        position: "pos-top-left",
        stepLabel: "Top Left",
        instruction: "Look at the dot in the top-left corner of your screen.",
      },
      {
        id: "topRight",
        position: "pos-top-right",
        stepLabel: "Top Right",
        instruction: "Look at the dot in the top-right corner of your screen.",
      },
      {
        id: "bottomLeft",
        position: "pos-bottom-left",
        stepLabel: "Bottom Left",
        instruction: "Look at the dot in the bottom-left corner of your screen.",
      },
      {
        id: "bottomRight",
        position: "pos-bottom-right",
        stepLabel: "Bottom Right",
        instruction: "Look at the dot in the bottom-right corner of your screen.",
      },
    ];

    /**
     * Number of frames to average for smoothing (10–15 recommended).
     * Higher = smoother but slower to react.
     */
    const SMOOTHING_FRAMES = 12;

    /** Milliseconds without a face before showing "NO FACE DETECTED". */
    const NO_FACE_TIMEOUT_MS = 1000;

    /** Minimum milliseconds between voice announcements. */
    const VOICE_COOLDOWN_MS = 2000;

    /** Default baseline when user has not calibrated yet (screen-center gaze). */
    const DEFAULT_BASELINE = { h: 0.5, v: 0.5 };

    /**
     * Voice messages spoken on status transitions.
     * Edit these strings to change what is announced.
     */
    const VOICE_MESSAGES = {
      inside:  "You're looking inside.",
      outside: "You're looking outside.",
    };

    // MediaPipe Face Mesh landmark indices (478-point model with iris)
    const LM = {
      LEFT_EYE_OUTER:  33,
      LEFT_EYE_INNER:  133,
      LEFT_EYE_TOP:    159,
      LEFT_EYE_BOTTOM: 145,
      RIGHT_EYE_INNER: 362,
      RIGHT_EYE_OUTER: 263,
      RIGHT_EYE_TOP:   386,
      RIGHT_EYE_BOTTOM:374,
      LEFT_IRIS_CENTER: 468,
      RIGHT_IRIS_CENTER:473,
    };

    // =========================================================================
    // DOM references
    // =========================================================================

    const video          = document.getElementById("webcam");
    const overlayCanvas  = document.getElementById("overlay-canvas");
    const overlayCtx     = overlayCanvas.getContext("2d");
    const cameraSelect   = document.getElementById("camera-select");
    const btnStart       = document.getElementById("btn-start");
    const btnStop        = document.getElementById("btn-stop");
    const btnCalibrate   = document.getElementById("btn-calibrate");
    const btnVoice       = document.getElementById("btn-voice");
    const statusIndicator= document.getElementById("status-indicator");
    const statusLabel    = document.getElementById("status-label");
    const metaScore      = document.getElementById("meta-score");
    const metaFace       = document.getElementById("meta-face");
    const metaCamera      = document.getElementById("meta-camera");
    const metaCalibration = document.getElementById("meta-calibration");
    const errorBanner     = document.getElementById("error-banner");
    const loadingOverlay  = document.getElementById("loading-overlay");

    const calOverlay    = document.getElementById("calibration-overlay");
    const calDot        = document.getElementById("cal-dot");
    const calInstruction= document.getElementById("cal-instruction");
    const calStepLabel  = document.getElementById("cal-step-label");
    const calCountdown  = document.getElementById("cal-countdown");
    const calProgressFill = document.getElementById("cal-progress-fill");
    const btnCalCancel  = document.getElementById("btn-cal-cancel");

    const dbg = {
      rawH:       document.getElementById("dbg-raw-h"),
      rawV:       document.getElementById("dbg-raw-v"),
      smoothH:    document.getElementById("dbg-smooth-h"),
      smoothV:    document.getElementById("dbg-smooth-v"),
      baselineH:  document.getElementById("dbg-baseline-h"),
      baselineV:  document.getElementById("dbg-baseline-v"),
      diff:       document.getElementById("dbg-diff"),
      thresholdH: document.getElementById("dbg-threshold-h"),
      thresholdV: document.getElementById("dbg-threshold-v"),
      calibration:document.getElementById("dbg-calibration"),
      fps:        document.getElementById("dbg-fps"),
      statusChange: document.getElementById("dbg-status-change"),
      voice:      document.getElementById("dbg-voice"),
      cooldown:   document.getElementById("dbg-cooldown"),
    };

    // =========================================================================
    // State
    // =========================================================================

    let faceLandmarker   = null;
    let mediaStream      = null;
    let animationId      = null;
    let lastVideoTime    = -1;
    let voiceEnabled     = false;
    let lastVoiceTime    = 0;
    let lastFaceTime     = 0;
    let currentStatus    = "idle";   // idle | inside | outside | noface
    let lastAnnouncedStatus = null;
    let statusChangeTime = null;
    let baseline         = { ...DEFAULT_BASELINE };
    let gazeBounds       = null;   // learned { hMargin, vMargin } after calibration
    let isCalibrated     = false;

    // Guided calibration wizard state
    let calibrationActive   = false;
    let calibrationStepIdx  = 0;
    let calibrationPoints   = {};
    let stepSamples         = [];
    let stepStartTime       = 0;
    let calibrationTimer    = null;
    let stepRetryCount      = 0;

    const gazeHistory = [];  // rolling buffer for smoothing

    // FPS tracking
    let frameCount   = 0;
    let fpsLastTime  = performance.now();
    let fpsEstimate  = 0;

    // =========================================================================
    // Utility helpers
    // =========================================================================

    function showError(msg) {
      errorBanner.textContent = msg;
      errorBanner.classList.add("visible");
    }

    function clearError() {
      errorBanner.textContent = "";
      errorBanner.classList.remove("visible");
    }

    function formatTime(ts) {
      if (!ts) return "—";
      return new Date(ts).toLocaleTimeString();
    }

    // =========================================================================
    // Gaze calculation
    // =========================================================================

    /**
     * Compute horizontal and vertical gaze ratios for one eye.
     * Returns { h, v } where 0.5 ≈ looking straight at the camera.
     *
     * Horizontal: iris position between outer and inner eye corners.
     * Vertical:   iris position between top and bottom eyelid landmarks.
     */
    function eyeGazeRatios(landmarks, outerIdx, innerIdx, topIdx, bottomIdx, irisIdx) {
      const outer  = landmarks[outerIdx];
      const inner  = landmarks[innerIdx];
      const top    = landmarks[topIdx];
      const bottom = landmarks[bottomIdx];
      const iris   = landmarks[irisIdx];

      const eyeWidth  = inner.x - outer.x;
      const eyeHeight = bottom.y - top.y;

      const h = eyeWidth  !== 0 ? (iris.x - outer.x) / eyeWidth  : 0.5;
      const v = eyeHeight !== 0 ? (iris.y - top.y)   / eyeHeight : 0.5;

      return { h: clamp(h, 0, 1), v: clamp(v, 0, 1) };
    }

    function clamp(v, min, max) {
      return Math.max(min, Math.min(max, v));
    }

    /**
     * Average left + right eye gaze ratios into a single { h, v } reading.
     */
    function computeGaze(landmarks) {
      const left = eyeGazeRatios(
        landmarks,
        LM.LEFT_EYE_OUTER, LM.LEFT_EYE_INNER,
        LM.LEFT_EYE_TOP, LM.LEFT_EYE_BOTTOM,
        LM.LEFT_IRIS_CENTER
      );
      const right = eyeGazeRatios(
        landmarks,
        LM.RIGHT_EYE_OUTER, LM.RIGHT_EYE_INNER,
        LM.RIGHT_EYE_TOP, LM.RIGHT_EYE_BOTTOM,
        LM.RIGHT_IRIS_CENTER
      );

      return {
        h: (left.h + right.h) / 2,
        v: (left.v + right.v) / 2,
      };
    }

    function pushGazeSample(raw) {
      gazeHistory.push(raw);
      if (gazeHistory.length > SMOOTHING_FRAMES) {
        gazeHistory.shift();
      }
    }

    function smoothedGaze() {
      if (gazeHistory.length === 0) return { h: 0.5, v: 0.5 };
      const sum = gazeHistory.reduce(
        (acc, g) => ({ h: acc.h + g.h, v: acc.v + g.v }),
        { h: 0, v: 0 }
      );
      const n = gazeHistory.length;
      return { h: sum.h / n, v: sum.v / n };
    }

    /**
     * INSIDE / OUTSIDE classification logic:
     *
     * After guided calibration:
     *   - baseline = center-dot gaze (inside reference)
     *   - gazeBounds = margins learned from corner dots × INSIDE_ZONE_FRACTION
     *   - Inside when |Δh| < hMargin AND |Δv| < vMargin
     *
     * Before calibration, falls back to Euclidean distance vs GAZE_THRESHOLD.
     */
    function classifyGaze(smooth) {
      const dH = Math.abs(smooth.h - baseline.h);
      const dV = Math.abs(smooth.v - baseline.v);
      const distance = Math.sqrt(dH * dH + dV * dV);

      let isInside;
      let confidence;

      if (isCalibrated && gazeBounds) {
        isInside = dH < gazeBounds.hMargin && dV < gazeBounds.vMargin;
        const hScore = gazeBounds.hMargin > 0 ? 1 - dH / gazeBounds.hMargin : 1;
        const vScore = gazeBounds.vMargin > 0 ? 1 - dV / gazeBounds.vMargin : 1;
        confidence = clamp(Math.min(hScore, vScore), 0, 1);
      } else {
        isInside = distance < GAZE_THRESHOLD;
        confidence = clamp(1 - distance / GAZE_THRESHOLD, 0, 1);
      }

      return { isInside, distance, confidence, dH, dV };
    }

    // =========================================================================
    // UI updates
    // =========================================================================

    function setStatus(status, confidence, faceDetected) {
      statusIndicator.className = `status-indicator ${status}`;

      const labels = {
        idle:    "CAMERA OFF",
        inside:  "LOOKING INSIDE",
        outside: "LOOKING OUTSIDE",
        noface:  "NO FACE DETECTED",
      };
      statusLabel.textContent = labels[status] || status.toUpperCase();

      if (status === "inside" || status === "outside") {
        metaScore.textContent = `${(confidence * 100).toFixed(1)}%`;
      } else {
        metaScore.textContent = "—";
      }

      metaFace.textContent = faceDetected ? "yes" : "no";

      if (status !== currentStatus && status !== "idle" && status !== "noface") {
        statusChangeTime = Date.now();
        dbg.statusChange.textContent = formatTime(statusChangeTime);
        maybeSpeak(status);
      }

      if (status === "noface" && currentStatus !== "noface") {
        statusChangeTime = Date.now();
        dbg.statusChange.textContent = formatTime(statusChangeTime);
      }

      currentStatus = status;
    }

    function updateDebug(raw, smooth, classification) {
      dbg.rawH.textContent    = raw.h.toFixed(4);
      dbg.rawV.textContent    = raw.v.toFixed(4);
      dbg.smoothH.textContent = smooth.h.toFixed(4);
      dbg.smoothV.textContent = smooth.v.toFixed(4);
      dbg.baselineH.textContent = baseline.h.toFixed(4) + (isCalibrated ? " (calibrated)" : " (default)");
      dbg.baselineV.textContent = baseline.v.toFixed(4);
      dbg.diff.textContent    = classification.distance.toFixed(4);
      if (gazeBounds) {
        dbg.thresholdH.textContent = gazeBounds.hMargin.toFixed(4);
        dbg.thresholdV.textContent = gazeBounds.vMargin.toFixed(4);
      } else {
        dbg.thresholdH.textContent = GAZE_THRESHOLD.toFixed(4) + " (fallback)";
        dbg.thresholdV.textContent = GAZE_THRESHOLD.toFixed(4) + " (fallback)";
      }
      dbg.calibration.textContent = isCalibrated ? "complete" : (calibrationActive ? "in progress" : "not done");
      dbg.fps.textContent     = fpsEstimate.toFixed(1);
      dbg.voice.textContent   = voiceEnabled ? "yes" : "no";

      const cooldownLeft = Math.max(0, VOICE_COOLDOWN_MS - (Date.now() - lastVoiceTime));
      dbg.cooldown.textContent = cooldownLeft > 0 ? `${(cooldownLeft / 1000).toFixed(1)}s` : "ready";
    }

    // =========================================================================
    // Voice (Speech Synthesis)
    // =========================================================================

    function maybeSpeak(status) {
      if (!voiceEnabled) return;
      if (status !== "inside" && status !== "outside") return;
      if (status === lastAnnouncedStatus) return;

      const now = Date.now();
      if (now - lastVoiceTime < VOICE_COOLDOWN_MS) return;

      const message = status === "inside" ? VOICE_MESSAGES.inside : VOICE_MESSAGES.outside;

      if (!window.speechSynthesis) return;

      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(message);
      utterance.rate  = 1.0;
      utterance.pitch = 1.0;
      window.speechSynthesis.speak(utterance);

      lastVoiceTime = now;
      lastAnnouncedStatus = status;
    }

    // =========================================================================
    // Overlay drawing (optional iris markers for visual feedback)
    // =========================================================================

    function drawOverlay(landmarks) {
      const w = overlayCanvas.width;
      const h = overlayCanvas.height;
      overlayCtx.clearRect(0, 0, w, h);

      if (!landmarks) return;

      const drawPoint = (idx, color, radius) => {
        const lm = landmarks[idx];
        overlayCtx.beginPath();
        overlayCtx.arc(lm.x * w, lm.y * h, radius, 0, Math.PI * 2);
        overlayCtx.fillStyle = color;
        overlayCtx.fill();
      };

      // Iris centers
      drawPoint(LM.LEFT_IRIS_CENTER,  "#2ecc71", 4);
      drawPoint(LM.RIGHT_IRIS_CENTER, "#2ecc71", 4);

      // Eye corners (dim)
      [LM.LEFT_EYE_OUTER, LM.LEFT_EYE_INNER, LM.RIGHT_EYE_OUTER, LM.RIGHT_EYE_INNER].forEach(idx => {
        drawPoint(idx, "rgba(200,212,224,0.4)", 2);
      });
    }

    function resizeOverlay() {
      overlayCanvas.width  = video.videoWidth  || CAMERA_WIDTH;
      overlayCanvas.height = video.videoHeight || CAMERA_HEIGHT;
    }

    // =========================================================================
    // MediaPipe initialization
    // =========================================================================

    async function createLandmarker(vision, delegate) {
      return FaceLandmarker.createFromOptions(vision, {
        baseOptions: {
          modelAssetPath:
            "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task",
          delegate,
        },
        runningMode: "VIDEO",
        numFaces: 1,
        minFaceDetectionConfidence: 0.5,
        minFacePresenceConfidence: 0.5,
        minTrackingConfidence: 0.5,
      });
    }

    async function initFaceLandmarker() {
      try {
        const vision = await FilesetResolver.forVisionTasks(
          "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm"
        );

        try {
          faceLandmarker = await createLandmarker(vision, "GPU");
        } catch (gpuErr) {
          console.warn("GPU delegate unavailable, falling back to CPU:", gpuErr);
          faceLandmarker = await createLandmarker(vision, "CPU");
        }

        loadingOverlay.classList.add("hidden");
        btnStart.disabled = false;
        await refreshCameraList();
      } catch (err) {
        loadingOverlay.textContent = "Failed to load MediaPipe model.";
        showError("MediaPipe initialization failed: " + err.message);
        console.error(err);
      }
    }

    // =========================================================================
    // Camera management
    // =========================================================================

    /**
     * Browsers hide deviceId until camera permission is granted, so options
     * may show "Camera 1" while value is still empty. Use index:N as fallback.
     */
    function cameraOptionValue(cam, index) {
      return cam.deviceId || `index:${index}`;
    }

    function parseCameraIndex(value) {
      if (!value || !value.startsWith("index:")) return -1;
      return parseInt(value.split(":")[1], 10);
    }

    async function listCameras() {
      const devices = await navigator.mediaDevices.enumerateDevices();
      return devices.filter(d => d.kind === "videoinput");
    }

    async function primeCameraPermission() {
      const tempStream = await navigator.mediaDevices.getUserMedia({
        video: true,
        audio: false,
      });
      tempStream.getTracks().forEach(t => t.stop());
    }

    async function refreshCameraList() {
      try {
        const cameras = await listCameras();

        const prevValue = cameraSelect.value;
        const prevIndex = parseCameraIndex(prevValue);
        const prevSelectedIndex = cameraSelect.selectedIndex;

        cameraSelect.innerHTML = '<option value="">— Select camera —</option>';

        cameras.forEach((cam, i) => {
          const opt = document.createElement("option");
          opt.value = cameraOptionValue(cam, i);
          opt.textContent = cam.label || `Camera ${i + 1}`;
          cameraSelect.appendChild(opt);
        });

        cameraSelect.disabled = cameras.length === 0;

        // Restore previous selection
        const arduCam = cameras.find(c => /ardu/i.test(c.label));
        if (arduCam) {
          cameraSelect.value = cameraOptionValue(arduCam, cameras.indexOf(arduCam));
        } else if (prevValue && [...cameraSelect.options].some(o => o.value === prevValue)) {
          cameraSelect.value = prevValue;
        } else if (prevIndex >= 0 && prevIndex < cameras.length) {
          cameraSelect.value = cameraOptionValue(cameras[prevIndex], prevIndex);
        } else if (prevSelectedIndex > 0 && prevSelectedIndex <= cameras.length) {
          const idx = prevSelectedIndex - 1;
          cameraSelect.value = cameraOptionValue(cameras[idx], idx);
        } else if (cameras.length === 1) {
          cameraSelect.value = cameraOptionValue(cameras[0], 0);
        }

        updateCameraMeta();
      } catch (err) {
        showError("Could not enumerate cameras: " + err.message);
      }
    }

    /**
     * Resolve the selected camera to a real deviceId.
     * Requests permission first when IDs are still hidden by the browser.
     */
    async function resolveSelectedDeviceId() {
      const selectedIndex = cameraSelect.selectedIndex - 1;
      if (selectedIndex < 0) {
        return null;
      }

      let cameras = await listCameras();
      if (selectedIndex >= cameras.length) {
        return null;
      }

      // Browsers omit deviceId until the user grants camera permission once
      if (!cameras[selectedIndex].deviceId) {
        await primeCameraPermission();
        await refreshCameraList();
        cameras = await listCameras();
      }

      const resolved = cameras[selectedIndex]?.deviceId;
      if (resolved) {
        return resolved;
      }

      const currentValue = cameraSelect.value;
      return currentValue && !currentValue.startsWith("index:") ? currentValue : null;
    }

    function buildVideoConstraints(deviceId) {
      const constraints = {
        video: {
          width:  { ideal: CAMERA_WIDTH },
          height: { ideal: CAMERA_HEIGHT },
          frameRate: { ideal: CAMERA_FPS },
        },
        audio: false,
      };

      if (deviceId) {
        constraints.video.deviceId = { exact: deviceId };
      }

      return constraints;
    }

    async function startCamera() {
      clearError();

      if (cameraSelect.selectedIndex <= 0) {
        showError("Please select a camera first.");
        return;
      }

      stopCamera();

      try {
        const deviceId = await resolveSelectedDeviceId();
        if (!deviceId) {
          showError("Could not access the selected camera. Try another device.");
          return;
        }

        mediaStream = await navigator.mediaDevices.getUserMedia(
          buildVideoConstraints(deviceId)
        );

        video.srcObject = mediaStream;
        await video.play();
        resizeOverlay();

        // Re-enumerate to get camera labels after permission granted
        await refreshCameraList();
        const match = [...cameraSelect.options].find(o => o.value === deviceId);
        if (match) {
          cameraSelect.value = deviceId;
        }
        updateCameraMeta();

        btnStart.disabled = true;
        btnStop.disabled  = false;
        btnCalibrate.disabled = false;
        cameraSelect.disabled = true;

        lastVideoTime = -1;
        lastFaceTime  = Date.now();
        gazeHistory.length = 0;
        currentStatus = "idle";
        lastAnnouncedStatus = null;

        detectLoop();

        // Prompt calibration automatically on first camera start
        if (!isCalibrated) {
          setTimeout(() => startCalibrationWizard(), 500);
        }
      } catch (err) {
        showError("Camera access failed: " + err.message + ". Ensure you are on HTTPS or localhost.");
        console.error(err);
      }
    }

    function stopCamera() {
      if (animationId) {
        cancelAnimationFrame(animationId);
        animationId = null;
      }

      if (mediaStream) {
        mediaStream.getTracks().forEach(t => t.stop());
        mediaStream = null;
      }

      video.srcObject = null;
      overlayCtx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

      btnStart.disabled = !faceLandmarker;
      btnStop.disabled  = true;
      btnCalibrate.disabled = true;
      cameraSelect.disabled = false;

      cancelCalibration();

      setStatus("idle", 0, false);
      metaScore.textContent = "—";
      metaFace.textContent  = "—";
      gazeHistory.length = 0;
      lastAnnouncedStatus = null;
    }

    function updateCameraMeta() {
      const selected = cameraSelect.options[cameraSelect.selectedIndex];
      metaCamera.textContent = selected ? selected.textContent : "—";
    }

    // =========================================================================
    // Detection loop
    // =========================================================================

    function detectLoop() {
      if (!faceLandmarker || !mediaStream) return;

      animationId = requestAnimationFrame(detectLoop);

      if (video.readyState < 2) return;

      const now = performance.now();

      // FPS estimate
      frameCount++;
      if (now - fpsLastTime >= 1000) {
        fpsEstimate = frameCount * 1000 / (now - fpsLastTime);
        frameCount  = 0;
        fpsLastTime = now;
      }

      if (video.currentTime === lastVideoTime) return;
      lastVideoTime = video.currentTime;

      let result;
      try {
        result = faceLandmarker.detectForVideo(video, now);
      } catch (err) {
        console.warn("detectForVideo error:", err);
        return;
      }

      const hasFace = result.faceLandmarks && result.faceLandmarks.length > 0;

      if (hasFace) {
        lastFaceTime = Date.now();
        const landmarks = result.faceLandmarks[0];
        const raw = computeGaze(landmarks);

        drawOverlay(landmarks);

        // During guided calibration, collect samples instead of classifying
        if (calibrationActive) {
          stepSamples.push(raw);
          return;
        }

        pushGazeSample(raw);
        const smooth = smoothedGaze();
        const cls    = classifyGaze(smooth);

        if (!isCalibrated) {
          statusIndicator.className = "status-indicator idle";
          statusLabel.textContent = "NEEDS CALIBRATION";
          metaFace.textContent = "yes";
          metaScore.textContent = "—";
        } else {
          const status = cls.isInside ? "inside" : "outside";
          setStatus(status, cls.confidence, true);
        }
        updateDebug(raw, smooth, cls);
      } else {
        drawOverlay(null);

        if (calibrationActive) {
          return;
        }

        const noFaceDuration = Date.now() - lastFaceTime;
        if (noFaceDuration > NO_FACE_TIMEOUT_MS) {
          setStatus("noface", 0, false);
          gazeHistory.length = 0;
        } else {
          // Brief dropout: keep last inside/outside status to avoid flicker
          metaFace.textContent = "no";
        }

        updateDebug(
          { h: 0, v: 0 },
          smoothedGaze(),
          { distance: 0, confidence: 0 }
        );
      }
    }

    // =========================================================================
    // Guided calibration wizard
    // =========================================================================

    function averageGazeSamples(samples) {
      const sum = samples.reduce(
        (acc, g) => ({ h: acc.h + g.h, v: acc.v + g.v }),
        { h: 0, v: 0 }
      );
      return { h: sum.h / samples.length, v: sum.v / samples.length };
    }

    function updateCalibrationMeta() {
      if (isCalibrated) {
        metaCalibration.textContent = "complete";
        metaCalibration.className = "meta-calibrated";
        btnCalibrate.textContent = "Recalibrate";
      } else {
        metaCalibration.textContent = calibrationActive ? "in progress…" : "not done";
        metaCalibration.className = "meta-not-calibrated";
        btnCalibrate.textContent = "Start Calibration";
      }
    }

    function showCalibrationOverlay() {
      calOverlay.style.setProperty("--cal-margin", `${CALIBRATION_DOT_MARGIN}px`);
      calOverlay.classList.remove("hidden");
      btnCalibrate.disabled = true;
    }

    function hideCalibrationOverlay() {
      calOverlay.classList.add("hidden");
      if (mediaStream) {
        btnCalibrate.disabled = false;
      }
    }

    function clearCalibrationTimer() {
      if (calibrationTimer) {
        clearInterval(calibrationTimer);
        calibrationTimer = null;
      }
    }

    function startCalibrationStep(index) {
      const step = CALIBRATION_STEPS[index];
      calibrationStepIdx = index;
      stepSamples = [];
      stepStartTime = Date.now();
      stepRetryCount = 0;

      calDot.className = `cal-dot ${step.position}`;
      calStepLabel.textContent = `Step ${index + 1} of ${CALIBRATION_STEPS.length}: ${step.stepLabel}`;
      calInstruction.textContent = step.instruction;
      calProgressFill.style.width = "0%";
      calCountdown.textContent = "Look at the dot and hold steady…";

      clearCalibrationTimer();
      calibrationTimer = setInterval(tickCalibrationStep, 100);
    }

    function tickCalibrationStep() {
      const elapsed = Date.now() - stepStartTime;
      const progress = Math.min(100, (elapsed / CALIBRATION_STEP_DURATION_MS) * 100);
      calProgressFill.style.width = `${progress}%`;

      const remainingSec = Math.ceil(Math.max(0, CALIBRATION_STEP_DURATION_MS - elapsed) / 1000);

      if (stepSamples.length < CALIBRATION_MIN_SAMPLES) {
        calCountdown.textContent = `Collecting gaze samples… ${stepSamples.length} / ${CALIBRATION_MIN_SAMPLES}`;
      } else {
        calCountdown.textContent = `Hold your gaze on the dot… ${remainingSec}s`;
      }

      if (elapsed < CALIBRATION_STEP_DURATION_MS) {
        return;
      }

      if (stepSamples.length < CALIBRATION_MIN_SAMPLES) {
        stepRetryCount++;
        if (stepRetryCount >= 4) {
          cancelCalibration("Calibration failed — face not detected reliably. Ensure good lighting and try again.");
          return;
        }
        stepStartTime = Date.now();
        stepSamples = [];
        calCountdown.textContent = "Face not detected enough — keep looking at the dot";
        return;
      }

      completeCalibrationStep();
    }

    function completeCalibrationStep() {
      clearCalibrationTimer();

      const step = CALIBRATION_STEPS[calibrationStepIdx];
      calibrationPoints[step.id] = averageGazeSamples(stepSamples);

      if (calibrationStepIdx < CALIBRATION_STEPS.length - 1) {
        startCalibrationStep(calibrationStepIdx + 1);
        return;
      }

      finishCalibrationWizard();
    }

    /**
     * Derive inside baseline and outside margins from the five calibration points.
     * Adjust INSIDE_ZONE_FRACTION to tune how close to center counts as "inside".
     */
    function finalizeCalibration() {
      const center = calibrationPoints.center;
      const corners = ["topLeft", "topRight", "bottomLeft", "bottomRight"]
        .map(id => calibrationPoints[id])
        .filter(Boolean);

      if (!center || corners.length < 4) {
        return false;
      }

      baseline = { h: center.h, v: center.v };

      const hSpread = Math.max(...corners.map(c => Math.abs(c.h - center.h)), 0.05);
      const vSpread = Math.max(...corners.map(c => Math.abs(c.v - center.v)), 0.05);

      gazeBounds = {
        hMargin: hSpread * INSIDE_ZONE_FRACTION,
        vMargin: vSpread * INSIDE_ZONE_FRACTION,
      };

      isCalibrated = true;
      gazeHistory.length = 0;
      lastAnnouncedStatus = null;
      return true;
    }

    function finishCalibrationWizard() {
      calibrationActive = false;
      clearCalibrationTimer();

      if (!finalizeCalibration()) {
        cancelCalibration("Calibration incomplete — please try again.");
        return;
      }

      hideCalibrationOverlay();
      clearError();
      updateCalibrationMeta();

      statusLabel.textContent = "CALIBRATION COMPLETE";
      statusIndicator.className = "status-indicator inside";

      if (voiceEnabled && window.speechSynthesis) {
        const utterance = new SpeechSynthesisUtterance("Calibration complete.");
        window.speechSynthesis.speak(utterance);
      }
    }

    function cancelCalibration(message) {
      calibrationActive = false;
      calibrationPoints = {};
      clearCalibrationTimer();
      hideCalibrationOverlay();
      updateCalibrationMeta();

      if (message) {
        showError(message);
      }
    }

    function startCalibrationWizard() {
      if (!mediaStream) {
        showError("Start the camera before calibrating.");
        return;
      }

      clearError();
      calibrationActive = true;
      calibrationPoints = {};
      isCalibrated = false;
      gazeBounds = null;
      gazeHistory.length = 0;
      lastAnnouncedStatus = null;

      updateCalibrationMeta();
      showCalibrationOverlay();
      startCalibrationStep(0);
    }

    // =========================================================================
    // Event listeners
    // =========================================================================

    btnStart.addEventListener("click", startCamera);
    btnStop.addEventListener("click", stopCamera);
    btnCalibrate.addEventListener("click", startCalibrationWizard);
    btnCalCancel.addEventListener("click", () => cancelCalibration("Calibration cancelled."));

    btnVoice.addEventListener("click", () => {
      voiceEnabled = !voiceEnabled;
      btnVoice.textContent = voiceEnabled ? "Voice Enabled" : "Enable Voice";
      btnVoice.classList.toggle("btn-voice-on", voiceEnabled);
      dbg.voice.textContent = voiceEnabled ? "yes" : "no";

      if (voiceEnabled && window.speechSynthesis) {
        // Prime speech synthesis (required on some browsers after user gesture)
        const prime = new SpeechSynthesisUtterance("");
        window.speechSynthesis.speak(prime);
        window.speechSynthesis.cancel();
      }
    });

    cameraSelect.addEventListener("change", updateCameraMeta);

    window.addEventListener("resize", resizeOverlay);

    // =========================================================================
    // Bootstrap
    // =========================================================================

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showError("This browser does not support getUserMedia. Use Chrome, Edge, or Safari on HTTPS/localhost.");
    } else if (location.protocol !== "https:" && location.hostname !== "localhost" && location.hostname !== "127.0.0.1") {
      showError("Camera access requires HTTPS or localhost. Current origin: " + location.origin);
    } else {
      initFaceLandmarker();
      updateCalibrationMeta();
    }
  </script>
</body>
</html>
