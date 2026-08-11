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
            $stage = 'summary';
        } elseif ($action === 'open_replay') {
            $service->openReplay($token);
            $stage = 'player';
        }
    }

    $grant = $_SESSION['replay_share_grant'] ?? array();
    if (is_array($grant) && (int)($grant['share_id'] ?? 0) === (int)$share['id']) {
        $stage = !empty($grant['privacy_accepted'])
            ? (!empty($grant['replay_opened']) ? 'player' : 'summary')
            : 'notice';
    }
    if ($stage === 'player') {
        $service->mediaGrant((string)$share['recording_id']);
        define('IPCA_PUBLIC_REPLAY', true);
        define('IPCA_PUBLIC_REPLAY_RECORDING_ID', (string)$share['recording_id']);
        require __DIR__ . '/admin/cockpit_recorder_replay.php';
        exit;
    }
    $sharedDebrief = $stage === 'summary'
        ? $service->debriefForGrantedShare($token)
        : array();
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (str_contains(strtolower($error), 'invalid or unavailable')) {
        $error = 'This replay link is invalid, expired, revoked, or unavailable.';
    }
}
$sharedDebrief = is_array($sharedDebrief ?? null) ? $sharedDebrief : array();

function replay_share_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function replay_share_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : array();
}

function replay_share_local_time(mixed $value, string $timezone): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($text, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone($timezone))->format('M j, Y H:i');
    } catch (Throwable) {
        return $text;
    }
}

function replay_share_hours(mixed $milliseconds): string
{
    return is_numeric($milliseconds)
        ? number_format(((float)$milliseconds) / 3600000, 1)
        : '—';
}

$noticeParagraphs = preg_split('/\R{2,}/', ReplayShareService::NOTICE_TEXT) ?: array();
$context = is_array($sharedDebrief['context'] ?? null) ? $sharedDebrief['context'] : array();
$legs = replay_share_array($context['legs'] ?? array());
$segments = replay_share_array($sharedDebrief['chronological_review_json'] ?? array());
$crew = replay_share_array($context['crew_json'] ?? array());
$studentName = '';
$instructorName = '';
foreach ($crew as $crewMember) {
    if (!is_array($crewMember)) continue;
    $role = strtolower(trim((string)($crewMember['role'] ?? '')));
    $name = trim((string)($crewMember['person_name'] ?? $crewMember['personName'] ?? ''));
    if ($studentName === '' && (!empty($crewMember['is_primary_customer']) || in_array($role, array('student', 'customer'), true))) {
        $studentName = $name;
    }
    if ($instructorName === '' && str_contains($role, 'instructor')) {
        $instructorName = $name;
    }
}
$timezone = trim((string)($context['operational_timezone'] ?? 'UTC')) ?: 'UTC';
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
    .card { width: min(980px, 100%); padding: 32px; border: 1px solid #dce3ed; border-radius: 22px; background: rgba(255,255,255,.96); box-shadow: 0 24px 65px rgba(30, 51, 79, .13); }
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
    .flight-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:24px 0; }
    .meta-item { padding:13px; border:1px solid #dce3ed; border-radius:12px; background:#f8fafc; }
    .meta-item span { display:block; color:#64748b; font-size:11px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; }
    .meta-item strong { display:block; margin-top:5px; }
    .section { margin-top:22px; padding-top:20px; border-top:1px solid #e2e8f0; }
    .section h2 { margin:0 0 12px; font-size:19px; }
    .legs { width:100%; border-collapse:collapse; font-size:14px; }
    .legs th,.legs td { padding:11px 9px; border-bottom:1px solid #e2e8f0; text-align:left; }
    .legs th { color:#64748b; font-size:11px; letter-spacing:.06em; text-transform:uppercase; }
    .assessment { padding:16px; border-radius:14px; background:#f8fafc; color:#334155; line-height:1.62; white-space:pre-line; }
    .draft { margin:14px 0; padding:12px 14px; border:1px solid #fcd34d; border-radius:12px; color:#854d0e; background:#fffbeb; }
    @media (max-width:680px) { body { padding:12px; } .card { padding:20px; } .legs-wrap { overflow-x:auto; } }
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
        <button type="submit">I accept — review flight debrief</button>
      </form>
    <?php elseif ($stage === 'summary'): ?>
      <h1>Flight Debrief</h1>
      <p class="intro">Review the flight legs and debrief before opening the Cockpit Recorder replay.</p>
      <?php if ((string)($sharedDebrief['status'] ?? '') === 'ai_draft'): ?>
        <div class="draft"><strong>AI-generated draft:</strong> this debrief has not yet been instructor-approved.</div>
      <?php endif; ?>
      <div class="flight-meta">
        <div class="meta-item"><span>Student</span><strong><?= replay_share_h($studentName ?: '—') ?></strong></div>
        <div class="meta-item"><span>Instructor</span><strong><?= replay_share_h($instructorName ?: '—') ?></strong></div>
        <div class="meta-item"><span>Aircraft</span><strong><?= replay_share_h($context['aircraft_registration'] ?? '—') ?></strong></div>
        <div class="meta-item"><span>Mission</span><strong><?= replay_share_h($context['mission_code'] ?? '—') ?></strong></div>
        <div class="meta-item"><span>Date</span><strong><?= replay_share_h($context['scheduled_date'] ?? '—') ?></strong></div>
      </div>

      <section class="section">
        <h2>Flight Legs</h2>
        <?php if ($legs === array()): ?>
          <p class="intro">No verified leg rows are available for this replay.</p>
        <?php else: ?>
          <div class="legs-wrap"><table class="legs">
            <thead><tr><th>Leg</th><th>From</th><th>To</th><th>Departure</th><th>Arrival</th><th>Hobbs</th><th>Tacho</th><th>Fuel</th><th>LDG</th></tr></thead>
            <tbody>
              <?php foreach ($legs as $index => $leg): ?>
                <?php if (!is_array($leg)) continue; ?>
                <tr>
                  <td><?= (int)$index + 1 ?></td>
                  <td><?= replay_share_h($leg['departure_airport_code'] ?? '—') ?></td>
                  <td><?= replay_share_h($leg['arrival_airport_code'] ?? '—') ?></td>
                  <td><?= replay_share_h(replay_share_local_time($leg['block_off_utc'] ?? $leg['departure_utc'] ?? $leg['start_utc'] ?? '', $timezone)) ?></td>
                  <td><?= replay_share_h(replay_share_local_time($leg['block_on_utc'] ?? $leg['arrival_utc'] ?? $leg['end_utc'] ?? '', $timezone)) ?></td>
                  <td><?= replay_share_h(replay_share_hours($leg['allocated_hobbs_duration_ms'] ?? null)) ?></td>
                  <td><?= replay_share_h(replay_share_hours($leg['allocated_tacho_duration_ms'] ?? null)) ?></td>
                  <td><?= replay_share_h(is_numeric($leg['fuel_used_usg'] ?? null) ? number_format((float)$leg['fuel_used_usg'], 1) . ' USG' : '—') ?></td>
                  <td><?= replay_share_h($leg['landing_event_count'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>

      <?php if ($segments !== array()): ?>
        <section class="section">
          <h2>Flight Review</h2>
          <?php foreach ($segments as $segment): ?>
            <?php if (!is_array($segment)) continue; ?>
            <h3><?= replay_share_h($segment['title'] ?? 'Flight segment') ?></h3>
            <div class="assessment"><?= replay_share_h($segment['narrative'] ?? '') ?></div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
      <section class="section">
        <h2>Overall Assessment</h2>
        <div class="assessment"><?= replay_share_h($sharedDebrief['mission_assessment_text'] ?? 'No overall assessment is available.') ?></div>
      </section>
      <section class="section">
        <h2>Main Takeaways</h2>
        <div class="assessment"><?= replay_share_h($sharedDebrief['summary_next_steps_text'] ?? 'No next steps are available.') ?></div>
      </section>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= replay_share_h($csrf) ?>">
        <input type="hidden" name="token" value="<?= replay_share_h($token) ?>">
        <input type="hidden" name="action" value="open_replay">
        <button type="submit">Open Cockpit Recorder Replay</button>
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
