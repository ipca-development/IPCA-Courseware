<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/flight_training/FormInstanceService.php';

cw_require_login();

$user = cw_current_user($pdo);
$userId = (int)($user['id'] ?? 0);
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    redirect(cw_home_path_for_role($role));
}

$service = new FormInstanceService($pdo);
$error = '';
$items = array();

if ($service->schemaReady()) {
    try {
        $items = $service->listInboxItems($userId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    $error = 'Internal forms inbox is not installed yet.';
}

function ifi_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('M j, Y H:i', $ts) : $value;
}

function ifi_badge(string $status): string
{
    return match (strtolower(trim($status))) {
        'completed' => 'ifi-badge ifi-badge--ok',
        'opened' => 'ifi-badge ifi-badge--info',
        'pending' => 'ifi-badge ifi-badge--warn',
        'cancelled' => 'ifi-badge ifi-badge--danger',
        default => 'ifi-badge',
    };
}

cw_header('Internal Forms Inbox');
?>

<style>
.ifi-page{display:flex;flex-direction:column;gap:18px}
.ifi-hero{padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.ifi-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.ifi-title{margin:0;font-size:30px;line-height:1.1}
.ifi-subtitle{margin:9px 0 0;max-width:720px;color:#dbeafe;font-size:14px;line-height:1.5}
.ifi-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.ifi-row{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:16px 18px;border-bottom:1px solid rgba(15,23,42,.06)}
.ifi-row:last-child{border-bottom:0}
.ifi-title-sm{margin:0;color:#102845;font-size:15px}
.ifi-meta{margin:4px 0 0;color:#64748b;font-size:12px}
.ifi-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.ifi-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;background:#1d4f89;color:#fff}
.ifi-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#f1f5f9;color:#334155}
.ifi-badge--ok{background:#dcfce7;color:#166534}
.ifi-badge--info{background:#dbeafe;color:#1e40af}
.ifi-badge--warn{background:#fef3c7;color:#92400e}
.ifi-badge--danger{background:#fff1f2;color:#be123c}
.ifi-empty,.ifi-error{padding:22px;border-radius:18px;background:#fff;color:#64748b;border:1px solid rgba(15,23,42,.08)}
.ifi-error{background:#fff1f2;color:#be123c;border-color:#fecdd3;font-weight:800}
@media (max-width:760px){.ifi-row{align-items:flex-start;flex-direction:column}.ifi-actions{justify-content:flex-start}}
</style>

<div class="ifi-page">
  <header class="ifi-hero">
    <p class="ifi-kicker">Instructor · Internal Documents & Forms</p>
    <h1 class="ifi-title">Internal Forms Inbox</h1>
    <p class="ifi-subtitle">Forms and internal documents assigned to you for review, completion, signature, or reference.</p>
  </header>

  <?php if ($error !== ''): ?>
    <div class="ifi-error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="ifi-card">
    <?php if ($items === array()): ?>
      <div class="ifi-empty">No internal forms or documents are assigned to you right now.</div>
    <?php else: ?>
      <?php foreach ($items as $item): ?>
        <?php $status = (string)($item['status'] ?? ''); ?>
        <article class="ifi-row">
          <div>
            <h2 class="ifi-title-sm"><?= h((string)$item['title']) ?></h2>
            <p class="ifi-meta">
              <?= h((string)($item['summary'] ?? '')) ?>
              · Updated <?= h(ifi_date($item['updated_at'] ?? null)) ?>
            </p>
          </div>
          <div class="ifi-actions">
            <span class="<?= h(ifi_badge($status)) ?>"><?= h($status ?: 'pending') ?></span>
            <a class="ifi-btn" href="<?= h((string)($item['action_url'] ?? '#')) ?>"><?= $status === 'completed' ? 'View' : 'Open' ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<?php cw_footer(); ?>
