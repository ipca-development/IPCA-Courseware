<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/helpers.php';

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
  <meta name="theme-color" content="#050505">
  <title>IPCA Airport Operations Flip Board</title>
  <link rel="stylesheet" href="/tv/assets/flipboard.css?v=<?= h($cssVersion) ?>">
</head>
<body class="fb-kiosk <?= $mode === 'night' ? 'is-night' : '' ?>">
  <div
    class="fb-stage"
    id="ipcaFlipBoardApp"
    data-screen-key="<?= h($screenKey) ?>"
    data-initial-mode="<?= h($mode) ?>"
    data-api-url="/tv/api/messages.php"
    data-poll-ms="7000">
    <div class="fb-ambient fb-ambient-left"></div>
    <div class="fb-ambient fb-ambient-right"></div>

    <main class="fb-board-shell" aria-label="IPCA airport operations board">
      <header class="fb-board-header">
        <div class="fb-brand-stack">
          <div class="fb-brand-kicker">IPCA.TRAINING</div>
          <h1>AIRPORT OPERATIONS</h1>
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
        </div>
      </section>

      <footer class="fb-footer">
        <div class="fb-footer-item">
          <span>SCREEN</span>
          <strong><?= h(strtoupper($screenKey)) ?></strong>
        </div>
        <div class="fb-footer-item">
          <span>AUDIO</span>
          <strong id="fbAudioState">ARMING</strong>
        </div>
        <button class="fb-audio-arm" id="fbAudioArm" type="button">Enable Airport PA Audio</button>
      </footer>
    </main>
  </div>

  <script src="/tv/assets/flipboard.js?v=<?= h($jsVersion) ?>" defer></script>
</body>
</html>
