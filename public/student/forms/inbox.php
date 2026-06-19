<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/flight_training/FormInstanceService.php';

cw_require_student();

$user = cw_current_user($pdo);
$userId = cw_student_view_user_id($pdo, $user);
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

function sfi_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('M j, Y H:i', $ts) : $value;
}

function sfi_badge(string $status): string
{
    return match (strtolower(trim($status))) {
        'completed' => 'sfi-badge sfi-badge--ok',
        'opened' => 'sfi-badge sfi-badge--info',
        'pending' => 'sfi-badge sfi-badge--warn',
        'cancelled' => 'sfi-badge sfi-badge--danger',
        default => 'sfi-badge',
    };
}

cw_header('My Internal Inbox');
?>

<style>
.sfi-page{display:flex;flex-direction:column;gap:18px}
.sfi-hero{padding:22px;border-radius:24px;background:linear-gradient(135deg,#102845,#1d4f89);color:#fff;box-shadow:0 18px 40px rgba(15,40,69,.18)}
.sfi-kicker{margin:0 0 7px;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe}
.sfi-title{margin:0;font-size:30px;line-height:1.1}
.sfi-subtitle{margin:9px 0 0;max-width:720px;color:#dbeafe;font-size:14px;line-height:1.5}
.sfi-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}
.sfi-row{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:16px 18px;border-bottom:1px solid rgba(15,23,42,.06)}
.sfi-row:last-child{border-bottom:0}
.sfi-title-sm{margin:0;color:#102845;font-size:15px}
.sfi-meta{margin:4px 0 0;color:#64748b;font-size:12px}
.sfi-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.sfi-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;background:#1d4f89;color:#fff}
.sfi-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;background:#f1f5f9;color:#334155}
.sfi-badge--ok{background:#dcfce7;color:#166534}
.sfi-badge--info{background:#dbeafe;color:#1e40af}
.sfi-badge--warn{background:#fef3c7;color:#92400e}
.sfi-badge--danger{background:#fff1f2;color:#be123c}
.sfi-empty,.sfi-error{padding:22px;border-radius:18px;background:#fff;color:#64748b;border:1px solid rgba(15,23,42,.08)}
.sfi-error{background:#fff1f2;color:#be123c;border-color:#fecdd3;font-weight:800}
@media (max-width:760px){.sfi-row{align-items:flex-start;flex-direction:column}.sfi-actions{justify-content:flex-start}}
</style>

<div class="sfi-page">
  <header class="sfi-hero">
    <p class="sfi-kicker">Student · Internal Documents & Forms</p>
    <h1 class="sfi-title">My Internal Inbox</h1>
    <p class="sfi-subtitle">Forms and internal documents assigned to you for review, completion, signature, or reference.</p>
  </header>

  <?php if ($error !== ''): ?>
    <div class="sfi-error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="sfi-card">
    <?php if ($items === array()): ?>
      <div class="sfi-empty">No internal forms or documents are assigned to you right now.</div>
    <?php else: ?>
      <?php foreach ($items as $item): ?>
        <?php
          $status = (string)($item['status'] ?? '');
          $href = (string)($item['action_url'] ?? '#');
          if (str_starts_with($href, '/student/forms/task.php') && $userId > 0 && strtolower((string)($user['role'] ?? '')) === 'admin') {
              $href .= (str_contains($href, '?') ? '&' : '?') . 'user_id=' . (int)$userId;
          }
        ?>
        <article class="sfi-row">
          <div>
            <h2 class="sfi-title-sm"><?= h((string)$item['title']) ?></h2>
            <p class="sfi-meta">
              <?= h((string)($item['summary'] ?? '')) ?>
              · Updated <?= h(sfi_date($item['updated_at'] ?? null)) ?>
            </p>
          </div>
          <div class="sfi-actions">
            <span class="<?= h(sfi_badge($status)) ?>"><?= h($status ?: 'pending') ?></span>
            <a class="sfi-btn" href="<?= h($href) ?>"><?= $status === 'completed' ? 'View' : 'Open' ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<?php cw_footer(); ?>
