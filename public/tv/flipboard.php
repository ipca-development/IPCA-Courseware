<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/tv_kiosk_config.php';

$kioskConfig = tv_kiosk_config();

$screenKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['screen'] ?? 'main'));
if ($screenKey === '') {
    $screenKey = 'main';
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'standard')));
if (!in_array($mode, ['standard', 'schedule', 'night'], true)) {
    $mode = 'standard';
}

$cssPath = __DIR__ . '/assets/flipboard.css';
$jsPath = __DIR__ . '/assets/flipboard.js';
$cssVersion = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
$jsVersion = is_file($jsPath) ? (string)filemtime($jsPath) : '1';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0d1d34">
  <title>IPCA Flip Board</title>
  <link rel="stylesheet" href="/tv/assets/flipboard.css?v=<?= h($cssVersion) ?>">
</head>
<body class="fb-kiosk <?= $mode === 'night' ? 'is-night' : '' ?>">
  <div
    class="fb-stage"
    id="ipcaFlipBoardApp"
    data-screen-key="<?= h($screenKey) ?>"
    data-initial-mode="<?= h($mode) ?>"
    data-api-url="/tv/api/messages.php"
    data-aircraft-api-url="/tv/api/aircraft_status.php"
    data-aircraft-board-api-url="/tv/api/aircraft_board.php"
    data-poll-ms="<?= h((string)max(5000, min(10000, (int)($kioskConfig['poll_ms'] ?? 7000)))) ?>"
    data-aircraft-poll-ms="<?= h((string)max(10000, min(60000, (int)($kioskConfig['aircraft_poll_ms'] ?? 15000)))) ?>"
    data-gate-label="<?= h((string)($kioskConfig['gate_label'] ?? 'SPC Gate')) ?>"
    data-gate-lat="<?= h((string)($kioskConfig['gate_lat'] ?? '33.6267')) ?>"
    data-gate-lon="<?= h((string)($kioskConfig['gate_lon'] ?? '-116.1600')) ?>"
    data-gate-radius-nm="<?= h((string)($kioskConfig['gate_radius_nm'] ?? '0.18')) ?>"
    data-home-airport="<?= h((string)($kioskConfig['home_airport'] ?? 'KTRM')) ?>"
    data-auto-audio="<?= ((int)($kioskConfig['audio_enabled'] ?? 1) === 1) ? '1' : '0' ?>">
    <main class="fb-board-shell" aria-label="IPCA operations flip board">
      <header class="fb-board-header">
        <div class="fb-brand-stack">
          <img
            class="fb-brand-logo"
            src="/assets/logo/ipca_logo_white.png"
            alt="IPCA International Pilot Center Alliance"
            width="240"
            height="auto">
        </div>
        <div class="fb-status-cluster">
          <div class="fb-status-light" id="fbStatusLight" aria-hidden="true"></div>
          <div>
            <div class="fb-status-label" id="fbStatusLabel">STANDARD OPS</div>
            <div class="fb-clock" id="fbClock">--:--:--</div>
          </div>
        </div>
      </header>

      <section class="fb-board-frame" id="fbBoardFrame">
        <div class="fb-frame-bolts" aria-hidden="true">
          <span></span><span></span><span></span><span></span>
        </div>
        <div class="fb-perspective">
          <div class="fb-message-board" id="fbMessageBoard"></div>
          <div class="fb-schedule-board" id="fbScheduleBoard" hidden></div>
          <div class="fb-aircraft-board" id="fbAircraftBoard" hidden></div>
        </div>
      </section>

      <footer class="fb-footer">
        <div class="fb-footer-item">
          <span>SCREEN</span>
          <strong><?= h(strtoupper($screenKey)) ?></strong>
        </div>
        <div class="fb-footer-item">
          <span>AUDIO</span>
          <strong id="fbAudioState">STARTING</strong>
        </div>
      </footer>
    </main>
  </div>

  <script src="/tv/assets/flipboard.js?v=<?= h($jsVersion) ?>" defer></script>
</body>
</html>
