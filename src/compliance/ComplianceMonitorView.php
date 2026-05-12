<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAccess.php';
require_once __DIR__ . '/ComplianceMonitorEngine.php';

/**
 * Shared renderer for the per-kind monitoring tab pages
 * (Live, CAP, FSTD, Safety, Cyber/Part-IS).
 *
 * Each calling page passes its monitor kind + page chrome and we render:
 *   - KPI strip (open / critical / acknowledged / resolved)
 *   - "Run rules now" button
 *   - Open alerts list with ack/resolve/dismiss buttons
 *   - Active rules list for this kind (with link to monitoring_rules.php)
 *
 * monitor_kind = NULL → "Live monitoring" cross-cut view that includes all kinds.
 */
final class ComplianceMonitorView
{
    /**
     * @param string $pageTitle  e.g. "CAP monitoring".
     * @param string $blurb      Short page intro.
     * @param string|null $kind  monitor_kind filter, or NULL for cross-cut.
     */
    public static function render(PDO $pdo, array $user, string $pageTitle, string $blurb, ?string $kind): void
    {
        $uid = (int)($user['id'] ?? 0);

        $flashKey = '_ipca_compliance_flash_monitor_' . ($kind ?? 'live');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            try {
                if ($action === 'run_all') {
                    $r = ComplianceMonitorEngine::runAllActive($pdo, $kind);
                    $_SESSION[$flashKey] = array(
                        'type' => 'success',
                        'message' => 'Monitor sweep complete: ' . (int)$r['hits'] . ' hit(s), ' . (int)$r['alerts'] . ' new alert(s).',
                    );
                }
                if ($action === 'ack_alert') {
                    ComplianceMonitorEngine::acknowledgeAlert($pdo, (int)($_POST['alert_id'] ?? 0), $uid);
                }
                if ($action === 'resolve_alert') {
                    ComplianceMonitorEngine::resolveAlert($pdo, (int)($_POST['alert_id'] ?? 0), $uid);
                }
                if ($action === 'dismiss_alert') {
                    ComplianceMonitorEngine::dismissAlert($pdo, (int)($_POST['alert_id'] ?? 0), $uid);
                }
            } catch (Throwable $e) {
                $_SESSION[$flashKey] = array('type' => 'error', 'message' => $e->getMessage());
            }
            $self = self::pagePath($kind);
            if (function_exists('redirect')) {
                redirect($self);
            }
            header('Location: ' . $self);
            exit;
        }

        $flash = null;
        if (!empty($_SESSION[$flashKey]) && is_array($_SESSION[$flashKey])) {
            $flash = $_SESSION[$flashKey];
            unset($_SESSION[$flashKey]);
        }

        $stats = ComplianceMonitorEngine::alertStats($pdo, $kind);
        $alerts = ComplianceMonitorEngine::listAlerts($pdo, $kind, 'OPEN', 50);
        $rules = ComplianceMonitorEngine::listRules($pdo, $kind, 50);
        $runs = ComplianceMonitorEngine::listRuns($pdo, null, 8);
        if ($kind !== null) {
            $runs = array_values(array_filter($runs, static function ($r) use ($rules) {
                foreach ($rules as $rl) {
                    if ((int)$rl['id'] === (int)$r['rule_id']) {
                        return true;
                    }
                }

                return false;
            }));
        }

        cw_header('Compliance · ' . $pageTitle);
        ?>
<style>
  .cmpmon-h1{margin:0 0 6px;font-size:24px;color:#0f172a;}
  .cmpmon-sub{margin:0 0 22px;color:#64748b;max-width:760px;line-height:1.55;}
  .cmpmon-kpis{
    display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
    gap:14px;margin-bottom:24px;max-width:1100px;
  }
  .cmpmon-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;}
  .cmpmon-label{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;}
  .cmpmon-big{font-size:28px;font-weight:800;color:#0f172a;line-height:1.1;margin-top:4px;}
  .cmpmon-card.is-crit .cmpmon-big{color:#b91c1c;}
  .cmpmon-card.is-warn .cmpmon-big{color:#b45309;}
  .cmpmon-card.is-ok .cmpmon-big{color:#0f766e;}
  .cmpmon-panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;max-width:1100px;}
  .cmpmon-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cmpmon-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
  }
  .cmpmon-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmpmon-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmpmon-pill{
    display:inline-block;padding:2px 8px;border-radius:999px;
    font-size:11px;font-weight:800;letter-spacing:.04em;
  }
  .cmpmon-pill.sev-CRITICAL{background:#fee2e2;color:#991b1b;}
  .cmpmon-pill.sev-HIGH{background:#ffedd5;color:#9a3412;}
  .cmpmon-pill.sev-MEDIUM{background:#fef3c7;color:#92400e;}
  .cmpmon-pill.sev-LOW{background:#d1fae5;color:#065f46;}
  .cmpmon-btn{background:#1e3c72;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:800;cursor:pointer;}
  .cmpmon-btn-small{padding:6px 10px;border:0;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;}
  .cmpmon-flash{padding:12px 16px;border-radius:12px;margin-bottom:16px;}
  .cmpmon-flash.is-ok{background:#d1fae5;color:#065f46;}
  .cmpmon-flash.is-warn{background:#fef3c7;color:#92400e;}
  .cmpmon-flash.is-danger{background:#fee2e2;color:#991b1b;}
</style>

<?php if ($flash !== null):
  $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger'); ?>
  <div class="cmpmon-flash <?= h($cls) ?>"><?= h((string)$flash['message']) ?></div>
<?php endif; ?>

<section style="padding:8px 0 40px;">
  <h1 class="cmpmon-h1"><?= h($pageTitle) ?></h1>
  <p class="cmpmon-sub"><?= h($blurb) ?></p>

  <div class="cmpmon-kpis">
    <div class="cmpmon-card <?= $stats['critical'] > 0 ? 'is-crit' : ($stats['open'] > 0 ? 'is-warn' : 'is-ok') ?>">
      <div class="cmpmon-label">Open</div>
      <div class="cmpmon-big"><?= (int)$stats['open'] ?></div>
    </div>
    <div class="cmpmon-card <?= $stats['critical'] > 0 ? 'is-crit' : '' ?>">
      <div class="cmpmon-label">Critical (open)</div>
      <div class="cmpmon-big"><?= (int)$stats['critical'] ?></div>
    </div>
    <div class="cmpmon-card">
      <div class="cmpmon-label">Acknowledged</div>
      <div class="cmpmon-big"><?= (int)$stats['acknowledged'] ?></div>
    </div>
    <div class="cmpmon-card is-ok">
      <div class="cmpmon-label">Resolved</div>
      <div class="cmpmon-big"><?= (int)$stats['resolved'] ?></div>
    </div>
  </div>

  <div class="cmpmon-panel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:12px;flex-wrap:wrap;">
      <h2 style="margin:0;font-size:16px;">Open alerts</h2>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="run_all">
        <button type="submit" class="cmpmon-btn">Run rules now</button>
      </form>
    </div>
    <?php if ($alerts === array()): ?>
      <p style="color:#64748b;margin:0;">No open alerts — system is clean for this scope.</p>
    <?php else: ?>
      <table class="cmpmon-table">
        <thead><tr>
          <th>Sev</th><th>Title</th><th>Rule</th><th>Subject</th><th>Raised</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($alerts as $a):
            $sev = (string)($a['severity'] ?? '');
            $subjectLink = self::subjectLink((string)$a['subject_type'], (int)$a['subject_id']);
          ?>
            <tr>
              <td><span class="cmpmon-pill sev-<?= h($sev) ?>"><?= h($sev) ?></span></td>
              <td>
                <div style="font-weight:700;color:#0f172a;"><?= h((string)$a['title']) ?></div>
                <?php if (!empty($a['body'])): ?>
                  <div style="color:#475569;font-size:12px;"><?= h((string)$a['body']) ?></div>
                <?php endif; ?>
              </td>
              <td class="cmpmon-mono">
                <?= h((string)($a['rule_code'] ?? '—')) ?>
                <div style="color:#64748b;font-size:11px;"><?= h((string)($a['rule_kind'] ?? '')) ?></div>
              </td>
              <td>
                <?php if ($subjectLink !== ''): ?>
                  <a href="<?= h($subjectLink) ?>" style="color:#1e3c72;font-weight:700;text-decoration:none;">
                    <?= h((string)$a['subject_type']) ?> #<?= (int)$a['subject_id'] ?>
                  </a>
                <?php else: ?>
                  <span class="cmpmon-mono"><?= h((string)$a['subject_type']) ?> #<?= (int)$a['subject_id'] ?></span>
                <?php endif; ?>
              </td>
              <td class="cmpmon-mono"><?= h(substr((string)($a['raised_at'] ?? ''), 0, 16)) ?></td>
              <td style="white-space:nowrap;">
                <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
                  <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" name="action" value="ack_alert"
                    class="cmpmon-btn-small" style="background:#e2e8f0;color:#0f172a;">Ack</button>
                  <button type="submit" name="action" value="resolve_alert"
                    class="cmpmon-btn-small" style="background:#0f766e;color:#fff;">Resolve</button>
                  <button type="submit" name="action" value="dismiss_alert"
                    class="cmpmon-btn-small" style="background:#fee2e2;color:#991b1b;"
                    onclick="return confirm('Dismiss this alert?');">Dismiss</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="cmpmon-panel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h2 style="margin:0;font-size:16px;">Rules in scope</h2>
      <a href="/admin/compliance/monitoring_rules.php<?= $kind !== null ? '?kind=' . urlencode($kind) : '' ?>"
         style="font-weight:700;color:#3730a3;">Manage rules →</a>
    </div>
    <?php if ($rules === array()): ?>
      <p style="color:#64748b;margin:0;">No rules defined for this scope yet. Define one in Monitoring Rules.</p>
    <?php else: ?>
      <table class="cmpmon-table">
        <thead><tr>
          <th>Code</th><th>Title</th><th>Kind</th><th>Severity</th><th>Active</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rules as $r): ?>
            <tr>
              <td class="cmpmon-mono"><?= h((string)$r['rule_code']) ?></td>
              <td><?= h((string)$r['title']) ?></td>
              <td><?= h((string)$r['monitor_kind']) ?></td>
              <td><span class="cmpmon-pill sev-<?= h((string)$r['alert_severity']) ?>"><?= h((string)$r['alert_severity']) ?></span></td>
              <td><?= ((int)$r['is_active'] === 1) ? 'Yes' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($runs !== array()): ?>
    <div class="cmpmon-panel">
      <h2 style="margin:0 0 14px;font-size:16px;">Recent runs</h2>
      <table class="cmpmon-table">
        <thead><tr><th>Started</th><th>Status</th><th>Hits</th><th>Trigger</th></tr></thead>
        <tbody>
          <?php foreach ($runs as $r): ?>
            <tr>
              <td class="cmpmon-mono"><?= h(substr((string)($r['started_at'] ?? ''), 0, 16)) ?></td>
              <td><?= h((string)$r['run_status']) ?></td>
              <td><?= (int)($r['hit_count'] ?? 0) ?></td>
              <td class="cmpmon-mono"><?= h((string)($r['trigger_source'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
        cw_footer();
    }

    private static function pagePath(?string $kind): string
    {
        switch ($kind) {
            case 'CAP':   return '/admin/compliance/cap_monitoring.php';
            case 'FSTD':  return '/admin/compliance/fstd_monitoring.php';
            case 'SAFETY':return '/admin/compliance/safety_monitoring.php';
            case 'CYBER': return '/admin/compliance/part_is.php';
            default:      return '/admin/compliance/live_monitoring.php';
        }
    }

    private static function subjectLink(string $type, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        switch (strtolower($type)) {
            case 'corrective_action': return '/admin/compliance/corrective_actions.php?id=' . $id;
            case 'finding':           return '/admin/compliance/findings.php?id=' . $id;
            case 'audit':             return '/admin/compliance/audits.php?id=' . $id;
            case 'case':              return '/admin/compliance/moc.php';
        }

        return '';
    }
}
