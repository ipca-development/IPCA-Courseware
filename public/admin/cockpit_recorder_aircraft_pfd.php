<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/PfdProfileService.php';

cw_require_admin();

$service = new CockpitAircraftService($pdo);
$error = '';
$notice = '';
$aircraft = null;
$profile = PfdProfileService::defaults();

$id = (int)($_GET['id'] ?? $_POST['aircraft_id'] ?? 0);
try {
    if ($id <= 0) {
        throw new RuntimeException('Aircraft id is required.');
    }
    $aircraft = $service->aircraftById($id);
    if (!$aircraft) {
        throw new RuntimeException('Aircraft not found.');
    }
    $profile = $service->pfdProfileForAircraftId($id);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = trim((string)($_POST['pfd_profile_json'] ?? ''));
        if ($raw === '') {
            throw new RuntimeException('PFD profile JSON is required.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('PFD profile JSON is invalid.');
        }
        $service->savePfdProfile($id, $decoded);
        $profile = $service->pfdProfileForAircraftId($id);
        $notice = 'PFD profile saved.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$profileJson = PfdProfileService::encode($profile);

cw_header('Aircraft PFD Profile');
?>
<style>
.cockpit-card { background: #fff; border: 1px solid rgba(15, 23, 42, .12); border-radius: 14px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); margin-bottom: 16px; }
.cockpit-muted { color: #64748b; font-size: 13px; }
.cockpit-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; margin-bottom: 16px; }
.cockpit-notice { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 12px; margin-bottom: 16px; }
.cockpit-btn { border: 0; border-radius: 999px; padding: 9px 14px; background: #1d4ed8; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; }
.cockpit-btn-secondary { background: #e2e8f0; color: #334155; }
textarea { width: 100%; min-height: 420px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px; }
</style>

<div class="cockpit-card">
  <h2 style="margin-top:0">PFD Profile — <?= h((string)($aircraft['display_name'] ?? $aircraft['registration'] ?? '')) ?></h2>
  <p class="cockpit-muted">
    Configure V-speed bugs, airspeed arc colors, and engine gauge zones used by the G3X replay display.
    Frequencies from Garmin CSV are shown live; only markings are configured here.
  </p>
  <p>
    <a class="cockpit-btn cockpit-btn-secondary" href="/admin/cockpit_recorder_aircraft.php?edit=<?= $id ?>">Back to aircraft</a>
  </p>
</div>

<?php if ($error !== ''): ?><div class="cockpit-error"><?= h($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cockpit-notice"><?= h($notice) ?></div><?php endif; ?>

<form method="post" class="cockpit-card">
  <input type="hidden" name="aircraft_id" value="<?= $id ?>">
  <h3 style="margin-top:0">Profile JSON</h3>
  <p class="cockpit-muted">Keys: <code>v_speeds</code>, <code>airspeed_arc</code>, <code>airspeed_tape</code>, <code>altitude_tape</code>, <code>gauges</code>, <code>units</code></p>
  <textarea name="pfd_profile_json" spellcheck="false"><?= h($profileJson) ?></textarea>
  <p style="margin-top:12px">
    <button class="cockpit-btn" type="submit">Save PFD Profile</button>
  </p>
</form>

<?php cw_footer(); ?>
