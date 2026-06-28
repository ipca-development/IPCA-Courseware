<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';

cw_require_admin();

$service = new CockpitAircraftService($pdo);
$error = '';
$notice = '';
$aircraft = array();
$edit = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $id = $service->saveAircraft(array(
            'id' => $_POST['id'] ?? 0,
            'registration' => $_POST['registration'] ?? '',
            'display_name' => $_POST['display_name'] ?? '',
            'aircraft_type' => $_POST['aircraft_type'] ?? '',
            'adsb_hex' => $_POST['adsb_hex'] ?? '',
            'home_airport' => $_POST['home_airport'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'active' => isset($_POST['active']) ? 1 : 0,
        ));
        $notice = 'Aircraft saved.';
        $edit = $service->aircraftById($id);
    }

    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0 && $edit === null) {
        $edit = $service->aircraftById($editId);
    }
    $aircraft = $service->adminAircraft();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$form = is_array($edit) ? $edit : array(
    'id' => 0,
    'registration' => '',
    'display_name' => '',
    'aircraft_type' => '',
    'adsb_hex' => '',
    'home_airport' => '',
    'notes' => '',
    'active' => 1,
);

cw_header('Cockpit Recorder Aircraft');
?>
<style>
.cockpit-aircraft-page { display: grid; gap: 18px; }
.cockpit-card { background: #fff; border: 1px solid rgba(15, 23, 42, .12); border-radius: 14px; padding: 18px; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); }
.cockpit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.cockpit-field label { display: block; font-weight: 700; color: #334155; margin-bottom: 5px; }
.cockpit-field input, .cockpit-field textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; padding: 9px 10px; }
.cockpit-actions { display: flex; gap: 10px; align-items: center; margin-top: 12px; }
.cockpit-btn { border: 0; border-radius: 999px; padding: 9px 14px; background: #1d4ed8; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; }
.cockpit-btn-secondary { background: #e2e8f0; color: #334155; }
.cockpit-muted { color: #64748b; font-size: 13px; }
.cockpit-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px; }
.cockpit-notice { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 12px; }
.cockpit-table-wrap { overflow-x: auto; }
.cockpit-table { width: 100%; border-collapse: collapse; min-width: 820px; }
.cockpit-table th, .cockpit-table td { border-bottom: 1px solid #e2e8f0; padding: 10px 8px; text-align: left; vertical-align: top; }
.cockpit-table th { color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
.cockpit-badge { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 700; background: #e2e8f0; color: #334155; }
.cockpit-badge-active { background: #dcfce7; color: #166534; }
</style>

<div class="cockpit-aircraft-page">
  <section class="cockpit-card">
    <h2 style="margin-top:0">Aircraft / Device Registry</h2>
    <p class="cockpit-muted">
      This registry is the source of truth for Cockpit Recorder ownship ADS-B enrichment and can later be reused by scheduling.
      The iPad app receives active aircraft from <code>/api/recordings/aircraft.php</code>.
    </p>
    <p><a class="cockpit-btn cockpit-btn-secondary" href="/admin/cockpit_recorder.php">Back to Cockpit Recorder</a></p>
  </section>

  <?php if ($error !== ''): ?>
    <div class="cockpit-error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?>
    <div class="cockpit-notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <section class="cockpit-card">
    <h3 style="margin-top:0"><?= ((int)($form['id'] ?? 0) > 0) ? 'Edit Aircraft' : 'Add Aircraft' ?></h3>
    <form method="post">
      <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
      <div class="cockpit-grid">
        <div class="cockpit-field">
          <label for="registration">Registration</label>
          <input id="registration" name="registration" value="<?= h((string)($form['registration'] ?? '')) ?>" maxlength="32" placeholder="N153PC" required>
        </div>
        <div class="cockpit-field">
          <label for="display_name">Display name</label>
          <input id="display_name" name="display_name" value="<?= h((string)($form['display_name'] ?? '')) ?>" maxlength="128" placeholder="N153PC">
        </div>
        <div class="cockpit-field">
          <label for="aircraft_type">Aircraft type</label>
          <input id="aircraft_type" name="aircraft_type" value="<?= h((string)($form['aircraft_type'] ?? '')) ?>" maxlength="64" placeholder="DA40">
        </div>
        <div class="cockpit-field">
          <label for="adsb_hex">ADS-B hex</label>
          <input id="adsb_hex" name="adsb_hex" value="<?= h((string)($form['adsb_hex'] ?? '')) ?>" maxlength="6" pattern="[a-fA-F0-9]{0,6}" placeholder="a4b605">
        </div>
        <div class="cockpit-field">
          <label for="home_airport">Home airport</label>
          <input id="home_airport" name="home_airport" value="<?= h((string)($form['home_airport'] ?? '')) ?>" maxlength="8" placeholder="KTRM">
        </div>
      </div>
      <div class="cockpit-field" style="margin-top:12px">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="3"><?= h((string)($form['notes'] ?? '')) ?></textarea>
      </div>
      <div class="cockpit-actions">
        <label><input type="checkbox" name="active" value="1" <?= ((int)($form['active'] ?? 1) === 1) ? 'checked' : '' ?>> Active</label>
        <button class="cockpit-btn" type="submit">Save Aircraft</button>
        <a class="cockpit-btn cockpit-btn-secondary" href="/admin/cockpit_recorder_aircraft.php">New</a>
        <?php if ((int)($form['id'] ?? 0) > 0): ?>
          <a class="cockpit-btn cockpit-btn-secondary" href="/admin/cockpit_recorder_aircraft_pfd.php?id=<?= (int)$form['id'] ?>">PFD Profile</a>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <section class="cockpit-card">
    <h3 style="margin-top:0">Aircraft</h3>
    <div class="cockpit-table-wrap">
      <table class="cockpit-table">
        <thead>
          <tr>
            <th>Registration</th>
            <th>Display</th>
            <th>Type</th>
            <th>ADS-B hex</th>
            <th>Home</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$aircraft): ?>
          <tr><td colspan="7" class="cockpit-muted">No aircraft registered yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($aircraft as $row): ?>
          <tr>
            <td><strong><?= h((string)($row['registration'] ?? '')) ?></strong></td>
            <td><?= h((string)($row['display_name'] ?? '')) ?></td>
            <td><?= h((string)($row['aircraft_type'] ?? '')) ?></td>
            <td><code><?= h((string)($row['adsb_hex'] ?? '')) ?></code></td>
            <td><?= h((string)($row['home_airport'] ?? '')) ?></td>
            <td>
              <?php if ((int)($row['active'] ?? 0) === 1): ?>
                <span class="cockpit-badge cockpit-badge-active">Active</span>
              <?php else: ?>
                <span class="cockpit-badge">Inactive</span>
              <?php endif; ?>
            </td>
            <td><a href="/admin/cockpit_recorder_aircraft.php?edit=<?= (int)($row['id'] ?? 0) ?>">Edit</a> · <a href="/admin/cockpit_recorder_aircraft_pfd.php?id=<?= (int)($row['id'] ?? 0) ?>">PFD</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php
cw_footer();
