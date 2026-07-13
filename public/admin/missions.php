<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/MissionCatalogService.php';

cw_require_admin();

$user = cw_current_user($pdo) ?: array();
$service = new MissionCatalogService($pdo);
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mission = $service->upsertMission((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), array(), (int)($user['id'] ?? 0));
        if (trim((string)($_POST['alias'] ?? '')) !== '') {
            $service->addAlias((int)$mission['id'], (string)$_POST['alias']);
        }
        $notice = 'Mission saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
try {
    $missions = $service->listMissions();
} catch (Throwable $e) {
    $missions = array();
    $error = $error !== '' ? $error : 'Mission catalog tables are not available yet.';
}

cw_header('Mission Catalog');
?>
<style>
.mission-page{display:grid;gap:18px}.mission-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.mission-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.mission-input{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px}.mission-button{border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;padding:9px 12px}.mission-muted{color:#64748b;font-size:13px}.mission-table{width:100%;border-collapse:collapse}.mission-table th,.mission-table td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left}.mission-notice{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px}.mission-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px}
</style>
<div class="mission-page">
  <section class="mission-card">
    <h2 style="margin-top:0">Mission Catalog</h2>
    <p class="mission-muted">Lightweight mission foundation: code, name, immutable versions, aliases, and future Flight Record assignment snapshots.</p>
  </section>
  <?php if ($notice !== ''): ?><div class="mission-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="mission-error"><?= h($error) ?></div><?php endif; ?>
  <section class="mission-card">
    <h3 style="margin-top:0">Add / Update Mission</h3>
    <form method="post" class="mission-grid">
      <input class="mission-input" name="code" placeholder="Code" required>
      <input class="mission-input" name="name" placeholder="Name" required>
      <input class="mission-input" name="alias" placeholder="Optional alias">
      <input class="mission-input" name="description" placeholder="Description">
      <button class="mission-button" type="submit">Save Mission</button>
    </form>
  </section>
  <section class="mission-card">
    <table class="mission-table">
      <thead><tr><th>Code</th><th>Name</th><th>Version</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($missions as $mission): ?>
        <tr><td><?= h((string)$mission['code']) ?></td><td><?= h((string)$mission['name']) ?></td><td><?= h((string)($mission['version_number'] ?? '')) ?></td><td><?= h((string)$mission['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$missions): ?><tr><td colspan="4" class="mission-muted">No missions available yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
<?php cw_footer(); ?>
