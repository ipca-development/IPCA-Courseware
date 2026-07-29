<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ReplayShareService.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$service = new ReplayShareService($pdo);
$token = trim((string)($_GET['t'] ?? $_POST['token'] ?? ''));
$error = '';
$share = array();
$stage = 'passcode';
$csrf = (string)($_SESSION['replay_share_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['replay_share_csrf'] = $csrf;
}

try {
    $share = $service->shareForToken($token);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('This request expired. Refresh the link and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'unlock') {
            $share = $service->unlock($token, (string)($_POST['passcode'] ?? ''));
            $stage = 'notice';
        } elseif ($action === 'accept_privacy') {
            $service->acceptPrivacy($token);
            $stage = 'player';
        }
    }

    $grant = $_SESSION['replay_share_grant'] ?? array();
    if (is_array($grant) && (int)($grant['share_id'] ?? 0) === (int)$share['id']) {
        $stage = !empty($grant['privacy_accepted']) ? 'player' : 'notice';
    }
    if ($stage === 'player') {
        $service->mediaGrant((string)$share['recording_id']);
        define('IPCA_PUBLIC_REPLAY', true);
        define('IPCA_PUBLIC_REPLAY_RECORDING_ID', (string)$share['recording_id']);
        require __DIR__ . '/admin/cockpit_recorder_replay.php';
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (str_contains(strtolower($error), 'invalid or unavailable')) {
        $error = 'This replay link is invalid, expired, revoked, or unavailable.';
    }
}

function replay_share_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$noticeParagraphs = preg_split('/\R{2,}/', ReplayShareService::NOTICE_TEXT) ?: array();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Private Flight Replay</title>
  <style>
    :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 28px; color: #172033; background: radial-gradient(circle at top, #eef5ff 0, #f7f9fc 46%, #edf1f7 100%); }
    .card { width: min(720px, 100%); padding: 32px; border: 1px solid #dce3ed; border-radius: 22px; background: rgba(255,255,255,.96); box-shadow: 0 24px 65px rgba(30, 51, 79, .13); }
    .eyebrow { margin: 0 0 9px; color: #47698f; font-size: 12px; font-weight: 800; letter-spacing: .11em; text-transform: uppercase; }
    h1 { margin: 0; color: #13233a; font-size: clamp(26px, 5vw, 38px); line-height: 1.12; }
    .intro { margin: 14px 0 26px; color: #536276; line-height: 1.6; }
    .notice { margin: 24px 0; padding: 22px; border: 1px solid #dce5f0; border-radius: 16px; background: #f8fafc; }
    .notice p { margin: 0 0 15px; color: #334155; line-height: 1.62; }
    .notice p:last-child { margin-bottom: 0; font-weight: 700; }
    label { display: block; margin-bottom: 8px; font-weight: 750; }
    input[type=text] { width: 100%; padding: 14px 16px; border: 1px solid #bdc9d8; border-radius: 12px; font: 700 19px/1 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .14em; text-transform: uppercase; }
    button { width: 100%; margin-top: 18px; padding: 14px 18px; border: 0; border-radius: 12px; color: white; background: #1f5e93; font-weight: 800; font-size: 16px; cursor: pointer; }
    button:hover { background: #184c78; }
    .error { margin: 0 0 20px; padding: 13px 15px; border: 1px solid #fecaca; border-radius: 12px; color: #991b1b; background: #fef2f2; }
    .expiry { margin: 18px 0 0; color: #64748b; font-size: 13px; text-align: center; }
  </style>
</head>
<body>
  <main class="card">
    <p class="eyebrow">IPCA.training · Secure debrief</p>
    <?php if ($stage === 'notice'): ?>
      <h1><?= replay_share_h(ReplayShareService::NOTICE_TITLE) ?></h1>
      <div class="notice">
        <?php foreach ($noticeParagraphs as $paragraph): ?>
          <p><?= replay_share_h($paragraph) ?></p>
        <?php endforeach; ?>
      </div>
      <?php if ($error !== ''): ?><div class="error"><?= replay_share_h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= replay_share_h($csrf) ?>">
        <input type="hidden" name="token" value="<?= replay_share_h($token) ?>">
        <input type="hidden" name="action" value="accept_privacy">
        <button type="submit">I accept — open private replay</button>
      </form>
    <?php else: ?>
      <h1>Private Flight Replay</h1>
      <p class="intro">Enter the separate passcode supplied by your instructor. Access is limited to this flight and expires automatically.</p>
      <?php if ($error !== ''): ?><div class="error"><?= replay_share_h($error) ?></div><?php endif; ?>
      <?php if ($share !== array()): ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= replay_share_h($csrf) ?>">
          <input type="hidden" name="token" value="<?= replay_share_h($token) ?>">
          <input type="hidden" name="action" value="unlock">
          <label for="passcode">Replay passcode</label>
          <input id="passcode" name="passcode" type="text" minlength="8" maxlength="8" inputmode="text" autocomplete="one-time-code" required autofocus>
          <button type="submit">Continue</button>
        </form>
        <p class="expiry">This link expires <?= replay_share_h(gmdate('M j, Y H:i', strtotime((string)$share['expires_at']))) ?> UTC.</p>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
