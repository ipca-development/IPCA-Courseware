<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/communication/CommunicationEnrollmentService.php';

cw_require_admin();

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

$snapshot = (new CommunicationEnrollmentService($pdo))->snapshot();
$stats = $snapshot['stats'];

cw_header('IPCA App');
?>
<style>
.ipca-app-page { max-width: 1100px; }
.ipca-app-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin: 0 0 18px;
}
.ipca-app-kpi .kpi-label { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #728198; }
.ipca-app-kpi .kpi-value { font-size: 28px; font-weight: 800; margin-top: 4px; }
.ipca-app-table { width: 100%; border-collapse: collapse; }
.ipca-app-table th, .ipca-app-table td { text-align: left; padding: 10px 8px; border-bottom: 1px solid rgba(15,23,42,.08); font-size: 14px; vertical-align: top; }
.ipca-app-table th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #728198; }
.ipca-app-muted { color: #728198; }
.ipca-app-kicker { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #728198; }
</style>

<div class="ipca-app-page">
  <div class="card">
    <div class="ipca-app-kicker">Enrollment</div>
    <h2 style="margin:6px 0 8px;">IPCA App</h2>
    <p class="ipca-app-note">
      People who have signed in on iPhone or iPad. Install, sign in with IPCA.training, and they appear here. Push is separate from being able to receive a DM.
    </p>
  </div>

  <div class="ipca-app-kpis">
    <div class="card ipca-app-kpi">
      <div class="kpi-label">Enrolled</div>
      <div class="kpi-value"><?= (int)$stats['enrolled_users'] ?></div>
    </div>
    <div class="card ipca-app-kpi">
      <div class="kpi-label">iPhone</div>
      <div class="kpi-value"><?= (int)$stats['iphones'] ?></div>
    </div>
    <div class="card ipca-app-kpi">
      <div class="kpi-label">iPad</div>
      <div class="kpi-value"><?= (int)$stats['ipads'] ?></div>
    </div>
    <div class="card ipca-app-kpi">
      <div class="kpi-label">Push ready</div>
      <div class="kpi-value"><?= (int)$stats['push_ready'] ?></div>
    </div>
    <div class="card ipca-app-kpi">
      <div class="kpi-label">Open acks</div>
      <div class="kpi-value"><?= (int)$stats['open_acknowledgements'] ?></div>
    </div>
    <div class="card ipca-app-kpi">
      <div class="kpi-label">Reports</div>
      <div class="kpi-value"><?= (int)$stats['community_reports'] ?></div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">People</h3>
    <?php if ($snapshot['people'] === array()): ?>
      <p class="ipca-app-muted">Nobody has signed in to the IPCA app yet.</p>
    <?php else: ?>
      <table class="ipca-app-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>iPhone</th>
            <th>iPad</th>
            <th>Push</th>
            <th>Last seen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($snapshot['people'] as $person): ?>
            <tr>
              <td>
                <a href="/admin/users/edit.php?id=<?= (int)$person['user_id'] ?>"><?= ipca_app_h($person['name']) ?></a>
              </td>
              <td><?= ipca_app_h($person['email']) ?></td>
              <td><?= ipca_app_h(ipca_app_role($person['role'])) ?></td>
              <td><?= !empty($person['has_iphone']) ? 'Yes' : '—' ?></td>
              <td><?= !empty($person['has_ipad']) ? 'Yes' : '—' ?></td>
              <td>
                <?php if (!empty($person['push_ready'])): ?>
                  <span class="app-badge app-badge-success">Ready</span>
                <?php else: ?>
                  <span class="app-badge app-badge-warn">Off</span>
                <?php endif; ?>
              </td>
              <td><?= ipca_app_h(ipca_app_when($person['last_seen_at_utc'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Devices</h3>
    <?php if ($snapshot['devices'] === array()): ?>
      <p class="ipca-app-muted">No enrolled devices.</p>
    <?php else: ?>
      <table class="ipca-app-table">
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
    <div class="card">
      <h3 style="margin-top:0;">Community reports</h3>
      <table class="ipca-app-table">
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
<?php
cw_footer();
