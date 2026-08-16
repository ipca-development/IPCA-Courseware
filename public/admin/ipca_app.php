<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/communication/CommunicationKernel.php';

cw_require_admin();

if (empty($_SESSION['ipca_app_csrf'])) {
    $_SESSION['ipca_app_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['ipca_app_csrf'];

function ipca_app_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ipca_app_when(?string $utc): string
{
    if ($utc === null || trim($utc) === '') {
        return '—';
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        $ts = strtotime($utc);
    }
    return $ts ? date('M j, Y · H:i', $ts) : '—';
}

function ipca_app_role(string $role): string
{
    $role = strtolower(trim($role));
    return match ($role) {
        'admin' => 'Admin',
        'chief_instructor' => 'Chief Instructor',
        'supervisor', 'instructor' => 'Instructor',
        'student' => 'Student',
        default => ucfirst(str_replace('_', ' ', $role)),
    };
}

$kernel = new CommunicationKernel($pdo);
$snapshot = $kernel->enrollment->snapshot();
$stats = $snapshot['stats'];
$categories = $kernel->trainingVideos->listCategories(true);
$entitlements = $kernel->trainingVideos->categoryEntitlementsByUser();
$categoryNames = array();
foreach ($categories as $category) {
    $categoryNames[(int)$category['id']] = (string)$category['name'];
}

cw_header('IPCA App');
?>
<link rel="stylesheet" href="/instructor/css/tcc_ia_shared.css">
<link rel="stylesheet" href="/admin/css/ipca_app_catalog.css">
<style>
.enroll-check { width:18px; height:18px; }
.enroll-cats { display:flex; flex-wrap:wrap; gap:6px; }
.enroll-modal-cats { display:flex; flex-direction:column; gap:8px; margin:12px 0; }
.enroll-modal-cats label { display:flex; gap:8px; align-items:center; font-weight:700; }
.enroll-field { margin:12px 0 0; }
.enroll-field label { display:block; font-size:12px; font-weight:700; margin:0 0 4px; color:#728198; }
.enroll-field input { width:100%; }
#enroll-person-modal .tcc-modal-card, #enroll-bulk-modal .tcc-modal-card { width:min(640px,96vw); }
</style>

<div class="ia-page">
  <section class="ia-hero-banner" aria-label="Enrollment">
    <div class="ia-hero-banner-head">
      <div class="ia-hero-banner-main">
        <div class="ia-hero-banner-kicker">IPCA App · Enrollment</div>
        <h1>Enrollment</h1>
        <p class="ia-hero-banner-sub">People who have signed in on iPhone or iPad. Category access is time-limited per person, not a required end date on each video. Leave until blank to keep access open-ended. Push is separate from being able to receive a DM.</p>
      </div>
      <div class="ia-hero-banner-actions">
        <button type="button" class="ia-hero-back-btn" id="enroll-bulk">Bulk category access</button>
        <a class="ia-hero-back-btn" href="/admin/ipca_training_videos.php">Training Videos</a>
      </div>
    </div>
    <div class="ia-hero-banner-chips">
      <span class="ia-chip--hero"><?= (int)$stats['enrolled_users'] ?> enrolled</span>
      <span class="ia-chip--hero"><?= (int)$stats['iphones'] ?> iPhone</span>
      <span class="ia-chip--hero"><?= (int)$stats['ipads'] ?> iPad</span>
      <span class="ia-chip--hero"><?= (int)$stats['push_ready'] ?> push ready</span>
      <span class="ia-chip--hero"><?= (int)$stats['open_acknowledgements'] ?> open acks</span>
      <span class="ia-chip--hero"><?= (int)$stats['community_reports'] ?> reports</span>
    </div>
  </section>

  <div class="ia-panel">
    <h3 style="margin-top:0;">People</h3>
    <?php if ($snapshot['people'] === array()): ?>
      <p class="ia-muted">Nobody has signed in to the IPCA app yet.</p>
    <?php else: ?>
      <table class="ia-table">
        <thead>
          <tr>
            <th></th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Category access</th>
            <th>Last seen</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($snapshot['people'] as $person): ?>
            <?php
              $userId = (int)$person['user_id'];
              $rows = $entitlements[$userId] ?? array();
              $labels = array();
              foreach ($rows as $row) {
                  $labels[] = $categoryNames[(int)$row['category_id']] ?? ('Category ' . (int)$row['category_id']);
              }
            ?>
            <tr>
              <td><input class="enroll-check enroll-person" type="checkbox" value="<?= $userId ?>"></td>
              <td>
                <a href="/admin/users/edit.php?id=<?= $userId ?>"><?= ipca_app_h($person['name']) ?></a>
              </td>
              <td><?= ipca_app_h($person['email']) ?></td>
              <td><?= ipca_app_h(ipca_app_role($person['role'])) ?></td>
              <td>
                <?php if ($labels === array()): ?>
                  <span class="ia-muted">Video grants only</span>
                <?php else: ?>
                  <div class="enroll-cats">
                    <?php foreach ($labels as $label): ?>
                      <span class="ia-badge ia-badge-live"><?= ipca_app_h($label) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><?= ipca_app_h(ipca_app_when($person['last_seen_at_utc'])) ?></td>
              <td>
                <button
                  type="button"
                  class="tcc-btn enroll-edit"
                  data-user-id="<?= $userId ?>"
                  data-name="<?= ipca_app_h($person['name']) ?>"
                  data-entitlements="<?= ipca_app_h((string)json_encode($rows)) ?>"
                >Edit access</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p id="enroll-message" class="ia-muted" style="margin-top:12px;"></p>
  </div>

  <div class="ia-panel">
    <h3 style="margin-top:0;">Devices</h3>
    <?php if ($snapshot['devices'] === array()): ?>
      <p class="ia-muted">No enrolled devices.</p>
    <?php else: ?>
      <table class="ia-table">
        <thead>
          <tr>
            <th>Person</th>
            <th>Platform</th>
            <th>Model</th>
            <th>App</th>
            <th>Push</th>
            <th>Last sync</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($snapshot['devices'] as $device): ?>
            <tr>
              <td><?= ipca_app_h($device['name']) ?></td>
              <td><?= ipca_app_h(ucfirst((string)$device['platform'])) ?></td>
              <td><?= ipca_app_h($device['model']) ?></td>
              <td><?= ipca_app_h($device['app_version']) ?></td>
              <td><?= !empty($device['push_ready']) ? 'Ready' : 'Off' ?></td>
              <td><?= ipca_app_h(ipca_app_when($device['last_sync_at_utc'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($snapshot['reports'] !== array()): ?>
    <div class="ia-panel">
      <h3 style="margin-top:0;">Community reports</h3>
      <table class="ia-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Reason</th>
            <th>Author</th>
            <th>Reporter</th>
            <th>Caption</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($snapshot['reports'] as $report): ?>
            <tr>
              <td><?= ipca_app_h(ipca_app_when($report['created_at_utc'])) ?></td>
              <td><?= ipca_app_h($report['reason']) ?></td>
              <td><?= ipca_app_h($report['author_name']) ?></td>
              <td><?= ipca_app_h($report['reporter_name']) ?></td>
              <td><?= ipca_app_h($report['caption']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="tcc-modal-overlay" id="enroll-person-modal">
  <div class="tcc-modal-card">
    <div class="tcc-modal-head">
      <div>
        <div class="tcc-modal-kicker">Category access</div>
        <div class="tcc-modal-title" id="enroll-person-title">Person</div>
      </div>
      <button type="button" class="tcc-modal-close" data-close="enroll-person-modal" aria-label="Close">&times;</button>
    </div>
    <div class="tcc-modal-body">
      <input type="hidden" id="enroll-user-id">
      <p class="ia-muted">Checked categories are required in addition to each video’s audience grant. Uncheck all to use video grants only. Leave until blank for open-ended access.</p>
      <div class="enroll-modal-cats" id="enroll-person-cats"></div>
      <div class="enroll-field">
        <label>Available from (UTC)</label>
        <input id="enroll-person-from" type="datetime-local">
      </div>
      <div class="enroll-field">
        <label>Available until (UTC)</label>
        <input id="enroll-person-until" type="datetime-local">
      </div>
      <div style="display:flex;gap:8px;margin-top:16px;">
        <button type="button" class="tcc-btn primary" id="enroll-person-save">Save</button>
        <button type="button" class="tcc-btn" data-close="enroll-person-modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<div class="tcc-modal-overlay" id="enroll-bulk-modal">
  <div class="tcc-modal-card">
    <div class="tcc-modal-head">
      <div>
        <div class="tcc-modal-kicker">Bulk</div>
        <div class="tcc-modal-title">Grant category access</div>
      </div>
      <button type="button" class="tcc-modal-close" data-close="enroll-bulk-modal" aria-label="Close">&times;</button>
    </div>
    <div class="tcc-modal-body">
      <p class="ia-muted">Adds the selected categories to the checked people. Existing categories stay. Leave until blank to keep access open-ended.</p>
      <div class="enroll-modal-cats" id="enroll-bulk-cats"></div>
      <div class="enroll-field">
        <label>Available from (UTC)</label>
        <input id="enroll-bulk-from" type="datetime-local">
      </div>
      <div class="enroll-field">
        <label>Available until (UTC)</label>
        <input id="enroll-bulk-until" type="datetime-local">
      </div>
      <div style="display:flex;gap:8px;margin-top:16px;">
        <button type="button" class="tcc-btn primary" id="enroll-bulk-save">Grant access</button>
        <button type="button" class="tcc-btn" data-close="enroll-bulk-modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const csrf = <?= json_encode($csrf) ?>;
  const categories = <?= json_encode($categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const api = '/admin/api/ipca_app_api.php';
  const message = document.getElementById('enroll-message');

  const iso = (value) => value ? new Date(value + 'Z').toISOString() : '';
  const setMessage = (text, kind) => {
    message.textContent = text || '';
    message.className = kind === 'ok' ? 'ia-ok' : (kind === 'err' ? 'ia-err' : 'ia-muted');
  };
  const openModal = (id) => document.getElementById(id).classList.add('open');
  const closeModal = (id) => document.getElementById(id).classList.remove('open');

  const renderCats = (hostId, selected) => {
    const chosen = {};
    (selected || []).forEach((id) => { chosen[String(id)] = true; });
    document.getElementById(hostId).innerHTML = categories.map((category) => `
      <label>
        <input type="checkbox" value="${category.id}" ${chosen[String(category.id)] ? 'checked' : ''}>
        ${category.name}
      </label>
    `).join('');
  };

  const selectedPeople = () => Array.from(document.querySelectorAll('.enroll-person:checked')).map((el) => Number(el.value));
  const selectedCats = (hostId) => Array.from(document.querySelectorAll('#' + hostId + ' input:checked')).map((el) => Number(el.value));

  document.querySelectorAll('[data-close]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.dataset.close));
  });
  document.getElementById('enroll-bulk').addEventListener('click', () => {
    if (!selectedPeople().length) {
      setMessage('Check one or more people first.', 'err');
      return;
    }
    renderCats('enroll-bulk-cats', []);
    openModal('enroll-bulk-modal');
  });
  document.querySelectorAll('.enroll-edit').forEach((button) => {
    button.addEventListener('click', () => {
      document.getElementById('enroll-user-id').value = button.dataset.userId;
      document.getElementById('enroll-person-title').textContent = button.dataset.name || 'Person';
      let rows = [];
      try { rows = JSON.parse(button.dataset.entitlements || '[]'); } catch (e) {}
      renderCats('enroll-person-cats', rows.map((row) => row.category_id));
      document.getElementById('enroll-person-from').value = '';
      document.getElementById('enroll-person-until').value = '';
      openModal('enroll-person-modal');
    });
  });
  document.getElementById('enroll-person-save').addEventListener('click', async () => {
    const userId = Number(document.getElementById('enroll-user-id').value);
    const from = iso(document.getElementById('enroll-person-from').value);
    const until = iso(document.getElementById('enroll-person-until').value);
    const entitlements = selectedCats('enroll-person-cats').map((categoryId) => ({
      category_id: categoryId,
      available_from_utc: from,
      available_until_utc: until,
    }));
    const result = await fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: csrf,
        action: 'replace_user_categories',
        user_id: userId,
        entitlements,
      }),
    }).then((r) => r.json());
    if (!result.ok) {
      setMessage(result.error || 'Could not save category access.', 'err');
      return;
    }
    window.location.reload();
  });
  document.getElementById('enroll-bulk-save').addEventListener('click', async () => {
    const result = await fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: csrf,
        action: 'grant_categories',
        user_ids: selectedPeople(),
        category_ids: selectedCats('enroll-bulk-cats'),
        available_from_utc: iso(document.getElementById('enroll-bulk-from').value),
        available_until_utc: iso(document.getElementById('enroll-bulk-until').value),
      }),
    }).then((r) => r.json());
    if (!result.ok) {
      setMessage(result.error || 'Could not grant category access.', 'err');
      return;
    }
    window.location.reload();
  });
})();
</script>
<?php
cw_footer();
