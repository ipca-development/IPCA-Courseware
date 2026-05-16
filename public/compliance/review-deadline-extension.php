<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/compliance/ComplianceDeadlineExtensionEngine.php';

function page_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;
$context = null;
$decisionMessage = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ComplianceDeadlineExtensionEngine::decideBatchByToken(
            $pdo,
            $token,
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['reviewer_name'] ?? ''),
            (string)($_POST['reviewer_email'] ?? ''),
            (string)($_POST['review_notes'] ?? '')
        );
        $decisionMessage = 'Review decision recorded.';
    }
    $context = ComplianceDeadlineExtensionEngine::reviewContextByToken($pdo, $token);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$batch = is_array($context) ? ($context['batch'] ?? array()) : array();
$items = is_array($context) ? ($context['items'] ?? array()) : array();
$isFinal = in_array((string)($batch['status'] ?? ''), array('approved', 'rejected', 'partially_approved', 'cancelled', 'superseded'), true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Deadline Extension Review</title>
  <style>
    :root{--navy:#12355f;--blue:#1f4079;--muted:#64748b;--line:#e2e8f0;--bg:#f4f7fb;}
    body{margin:0;background:var(--bg);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;}
    .page{max-width:1080px;margin:0 auto;padding:28px 18px 44px;}
    .hero{background:linear-gradient(135deg,#12355f,#1f4079);color:#fff;border-radius:22px;padding:24px;box-shadow:0 18px 45px rgba(18,53,95,.22);}
    .hero h1{margin:0;font-size:28px;letter-spacing:-.03em}.hero p{margin:8px 0 0;color:rgba(255,255,255,.82)}
    .card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.06);padding:18px;margin-top:16px;}
    .grid{display:grid;grid-template-columns:190px 1fr;gap:8px 16px}.grid dt{color:var(--muted);font-weight:800}.grid dd{margin:0;font-weight:720}
    .pill{display:inline-flex;align-items:center;border:1px solid #cbd5e1;background:#f8fafc;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:850;color:#334155}
    .pill.pending{border-style:dashed;border-color:#f59e0b;background:#fffbeb;color:#92400e}.pill.ok{border-color:#bbf7d0;background:#ecfdf5;color:#166534}.pill.bad{border-color:#fecaca;background:#fef2f2;color:#991b1b}
    table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}th,td{border-bottom:1px solid #eef2f7;padding:10px;vertical-align:top}
    .actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.btn{border:0;border-radius:12px;background:var(--navy);color:#fff;font-weight:850;padding:10px 16px;cursor:pointer}.btn.secondary{background:#f1f5f9;color:#17345d;border:1px solid #cbd5e1}.btn.danger{background:#991b1b}.btn[disabled]{background:#e5e7eb;color:#64748b;cursor:not-allowed}
    label span{display:block;color:var(--muted);font-size:12px;font-weight:850;margin-bottom:4px}input,textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:9px 10px;font:inherit}textarea{min-height:90px}
    .formgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.notice{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:14px;padding:12px;margin-top:16px}.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}
    @media(max-width:760px){.grid,.formgrid{grid-template-columns:1fr}.actions{justify-content:flex-start}}
  </style>
</head>
<body>
  <main class="page">
    <section class="hero">
      <h1>Deadline Extension Review</h1>
      <p>Secure authority/internal review page for corrective action deadline extensions.</p>
    </section>

    <?php if ($error !== null): ?>
      <section class="card error"><?= page_h($error) ?></section>
    <?php else: ?>
      <?php if ($decisionMessage !== null): ?><div class="notice"><?= page_h($decisionMessage) ?></div><?php endif; ?>

      <section class="card">
        <dl class="grid">
          <dt>Audit reference</dt><dd><?= page_h((string)($batch['audit_code'] ?? '—')) ?></dd>
          <dt>Audit title</dt><dd><?= page_h((string)($batch['audit_title'] ?? '—')) ?></dd>
          <dt>Authority/internal</dt><dd><?= page_h((string)($batch['request_type'] ?? '—')) ?></dd>
          <dt>Audit date/window</dt><dd><?= page_h(substr((string)($batch['start_date'] ?? ''), 0, 10)) ?><?= !empty($batch['end_date']) ? ' → ' . page_h(substr((string)$batch['end_date'], 0, 10)) : '' ?></dd>
          <dt>Request reference</dt><dd><?= page_h((string)($batch['request_reference'] ?? '—')) ?></dd>
          <dt>Submitted date</dt><dd><?= page_h(substr((string)($batch['submitted_at'] ?? ''), 0, 16)) ?></dd>
          <dt>Status</dt><dd><span class="pill"><?= page_h((string)($batch['status'] ?? '—')) ?></span></dd>
        </dl>
      </section>

      <section class="card">
        <h2 style="margin:0 0 12px;font-size:18px;">Requested Extensions</h2>
        <table>
          <thead><tr><th>CAP</th><th>Finding</th><th>Title</th><th>Current deadline</th><th>Requested deadline</th><th>Explanation</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= page_h((string)$item['action_code']) ?></td>
              <td><?= page_h((string)($item['finding_reference'] ?: $item['finding_code'])) ?></td>
              <td><?= page_h((string)$item['action_title']) ?></td>
              <td><span class="pill"><?= page_h(substr((string)$item['previous_approved_deadline'], 0, 10)) ?></span></td>
              <td><span class="pill pending"><?= page_h(substr((string)$item['requested_deadline'], 0, 10)) ?></span></td>
              <td><?= page_h((string)$item['explanation']) ?></td>
              <td><span class="pill <?= (string)$item['status'] === 'approved' ? 'ok' : ((string)$item['status'] === 'rejected' ? 'bad' : 'pending') ?>"><?= page_h((string)$item['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="card">
        <h2 style="margin:0 0 12px;font-size:18px;">Authority Action</h2>
        <?php if ($isFinal): ?>
          <p style="margin:0;color:#64748b;">This request has already been reviewed.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="token" value="<?= page_h($token) ?>">
            <div class="formgrid">
              <label><span>Reviewer name *</span><input name="reviewer_name" required value="<?= page_h((string)($batch['recipient_name'] ?? '')) ?>"></label>
              <label><span>Reviewer email *</span><input type="email" name="reviewer_email" required value="<?= page_h((string)($batch['recipient_email'] ?? '')) ?>"></label>
            </div>
            <label style="display:block;margin-top:12px;"><span>Review notes</span><textarea name="review_notes"></textarea></label>
            <div class="actions" style="margin-top:14px;">
              <button type="submit" class="btn" name="decision" value="approved">Approve Extension Request</button>
              <button type="submit" class="btn danger" name="decision" value="rejected">Reject Extension Request</button>
            </div>
          </form>
        <?php endif; ?>
      </section>

      <section class="card">
        <div class="actions">
          <button class="btn secondary" disabled title="PDF export will be connected to the existing audit report generator.">Print Audit Report PDF</button>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
