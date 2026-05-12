<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceManualControlEngine.php';

compliance_require_access($pdo);

function moc_count(PDO $pdo, string $sql): int
{
    try {
        $v = $pdo->query($sql)->fetchColumn();

        return (int)$v;
    } catch (Throwable) {
        return 0;
    }
}

cw_header('Compliance · Management of Change');

$crTotal = moc_count($pdo, 'SELECT COUNT(*) FROM ipca_compliance_manual_change_requests');
$crOpen = moc_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_change_requests WHERE status NOT IN ('RELEASED','CANCELLED','REJECTED')"
);
$drDraft = moc_count($pdo, "SELECT COUNT(*) FROM ipca_compliance_manual_drafts WHERE status IN ('DRAFT','UNDER_REVIEW')");
$pkgActive = moc_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_release_packages WHERE status NOT IN ('RELEASED','SUPERSEDED','CANCELLED')"
);

$mocCases = array();
try {
    $st = $pdo->query(
        "SELECT id, case_code, title, status, opened_at FROM ipca_compliance_cases
         WHERE case_type = 'MANAGEMENT_OF_CHANGE'
         ORDER BY updated_at DESC, id DESC LIMIT 25"
    );
    $mocCases = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable) {
    $mocCases = array();
}

$upcomingPkgs = array();
try {
    $st = $pdo->query(
        "SELECT package_code, title, effective_date, status FROM ipca_compliance_manual_release_packages
         WHERE effective_date IS NOT NULL AND effective_date >= CURDATE()
         ORDER BY effective_date ASC LIMIT 15"
    );
    $upcomingPkgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable) {
    $upcomingPkgs = array();
}

$recentCr = ComplianceManualControlEngine::listChangeRequests($pdo, 12);
?>
<section style="padding:8px 0 40px;">
  <h1 style="margin:0 0 8px;font-size:24px;">Management of change</h1>
  <p style="color:#64748b;margin:0 0 24px;max-width:720px;line-height:1.55;">
    Phase 4+ cross-cut: open manual change work, drafts in flight, and release packages not yet final.
    Full case linking lives in <code>ipca_compliance_cases</code> / <code>ipca_compliance_case_links</code> (wire in a later phase).
  </p>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:28px;max-width:960px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Change requests</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= (int)$crTotal ?></div>
      <div style="font-size:13px;color:#64748b;"><?= (int)$crOpen ?> open / in-flight</div>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Draft manuals</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= (int)$drDraft ?></div>
      <div style="font-size:13px;color:#64748b;">draft / under review</div>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Packages</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= (int)$pkgActive ?></div>
      <div style="font-size:13px;color:#64748b;">not released / superseded</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;max-width:1100px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
      <h2 style="margin:0 0 12px;font-size:16px;">Recent change requests</h2>
      <ul style="margin:0;padding:0;list-style:none;font-size:13px;">
        <?php foreach ($recentCr as $r): ?>
          <li style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:12px;">
            <span><a href="/admin/compliance/change_requests.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#1e3c72;"><?= h((string)$r['request_code']) ?></a>
              <span style="color:#64748b;"> — <?= h((string)$r['status']) ?></span></span>
          </li>
        <?php endforeach; ?>
        <?php if ($recentCr === array()): ?>
          <li style="color:#64748b;padding:12px 0;">None yet.</li>
        <?php endif; ?>
      </ul>
      <p style="margin:14px 0 0;"><a href="/admin/compliance/change_requests.php" style="font-weight:700;color:#3730a3;">All requests →</a></p>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
      <h2 style="margin:0 0 12px;font-size:16px;">Upcoming effective dates</h2>
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <?php foreach ($upcomingPkgs as $p): ?>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:8px 0;font-family:monospace;font-size:12px;"><?= h((string)$p['package_code']) ?></td>
            <td style="padding:8px 0;"><?= h((string)($p['effective_date'] ?? '')) ?></td>
            <td style="padding:8px 0;color:#64748b;"><?= h((string)$p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($upcomingPkgs === array()): ?>
        <p style="color:#64748b;font-size:13px;margin:8px 0 0;">No dated packages ahead.</p>
      <?php endif; ?>
      <p style="margin:14px 0 0;">
        <a href="/admin/compliance/manual_approved.php" style="font-weight:700;color:#3730a3;">Packages →</a>
        · <a href="/admin/compliance/manual_drafts.php" style="font-weight:700;color:#3730a3;">Drafts →</a>
      </p>
    </div>
  </div>

  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-top:24px;max-width:1100px;">
    <h2 style="margin:0 0 12px;font-size:16px;">MoC cases (case_type = MANAGEMENT_OF_CHANGE)</h2>
    <?php if ($mocCases !== array()): ?>
      <table style="width:100%;font-size:14px;border-collapse:collapse;">
        <thead><tr style="text-align:left;background:#f8fafc;"><th style="padding:8px;">Code</th><th style="padding:8px;">Title</th><th style="padding:8px;">Status</th></tr></thead>
        <tbody>
          <?php foreach ($mocCases as $c): ?>
            <tr style="border-top:1px solid #e2e8f0;">
              <td style="padding:8px;font-family:monospace;font-size:12px;"><?= h((string)$c['case_code']) ?></td>
              <td style="padding:8px;"><?= h((string)$c['title']) ?></td>
              <td style="padding:8px;"><?= h((string)$c['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin:12px 0 0;font-size:13px;color:#64748b;">Create cases from SQL or a future “New MoC case” action — schema is ready.</p>
    <?php else: ?>
      <p style="color:#64748b;font-size:14px;margin:0;">No MoC cases recorded yet.</p>
    <?php endif; ?>
  </div>
</section>
<?php
cw_footer();
