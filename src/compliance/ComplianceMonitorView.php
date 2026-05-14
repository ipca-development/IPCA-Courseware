<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAccess.php';
require_once __DIR__ . '/ComplianceMonitorEngine.php';
require_once __DIR__ . '/ComplianceUi.php';

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

        $tone = ($stats['critical'] ?? 0) > 0
            ? 'crit'
            : (($stats['open'] ?? 0) > 0 ? 'warn' : 'ok');

        compliance_page_open(array(
            'overline' => 'Monitoring',
            'title' => $pageTitle,
            'description' => $blurb,
            'flash' => $flash,
            'stats' => array(
                array('label' => 'Open', 'value' => (int)$stats['open'], 'tone' => $tone),
                array('label' => 'Critical (open)', 'value' => (int)$stats['critical'], 'tone' => ($stats['critical'] ?? 0) > 0 ? 'crit' : ''),
                array('label' => 'Acknowledged', 'value' => (int)$stats['acknowledged']),
                array('label' => 'Resolved', 'value' => (int)$stats['resolved'], 'tone' => 'ok'),
            ),
        ));
        ?>

<section class="cmp-card">
  <div class="cmp-card-head">
    <h2 class="cmp-card-title">Open alerts</h2>
    <form method="post" style="margin:0;">
      <input type="hidden" name="action" value="run_all">
      <button type="submit">Run rules now</button>
    </form>
  </div>

  <?php if ($alerts === array()): ?>
    <div class="cmp-empty">
      <div class="cmp-empty-title">No open alerts</div>
      <p style="margin:0;">The monitor sweep is clean for this scope.</p>
    </div>
  <?php else: ?>
    <div class="compliance-table-wrap">
    <table class="cmp-table compliance-table">
      <thead><tr>
        <th>Severity</th><th>Title</th><th>Rule</th><th>Subject</th><th>Raised</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($alerts as $a):
          $sev = (string)($a['severity'] ?? '');
          $subjectLink = self::subjectLink((string)$a['subject_type'], (int)$a['subject_id']);
        ?>
          <tr>
            <td><span class="cmp-pill sev-<?= h($sev) ?>"><?= h($sev) ?></span></td>
            <td>
              <div style="font-weight:680;"><?= h((string)$a['title']) ?></div>
              <?php if (!empty($a['body'])): ?>
                <div class="cmp-mono" style="margin-top:4px;"><?= h((string)$a['body']) ?></div>
              <?php endif; ?>
            </td>
            <td class="cmp-mono">
              <?= h((string)($a['rule_code'] ?? '—')) ?>
              <div style="margin-top:3px;"><?= h((string)($a['rule_kind'] ?? '')) ?></div>
            </td>
            <td>
              <?php if ($subjectLink !== ''): ?>
                <a href="<?= h($subjectLink) ?>"><?= h((string)$a['subject_type']) ?> #<?= (int)$a['subject_id'] ?></a>
              <?php else: ?>
                <span class="cmp-mono"><?= h((string)$a['subject_type']) ?> #<?= (int)$a['subject_id'] ?></span>
              <?php endif; ?>
            </td>
            <td class="cmp-mono"><?= h(substr((string)($a['raised_at'] ?? ''), 0, 16)) ?></td>
            <td style="white-space:nowrap;">
              <form method="post" style="display:inline-flex;gap:6px;align-items:center;">
                <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" name="action" value="ack_alert">Ack</button>
                <button type="submit" name="action" value="resolve_alert">Resolve</button>
                <button type="submit" name="action" value="dismiss_alert"
                  onclick="return confirm('Dismiss this alert?');">Dismiss</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<section class="cmp-card">
  <div class="cmp-card-head">
    <h2 class="cmp-card-title">Rules in scope</h2>
    <a class="cmp-btn cmp-btn-secondary"
       href="/admin/compliance/monitoring_rules.php<?= $kind !== null ? '?kind=' . urlencode($kind) : '' ?>">
      Manage rules &rarr;
    </a>
  </div>

  <?php if ($rules === array()): ?>
    <div class="cmp-empty">
      <div class="cmp-empty-title">No rules defined for this scope yet</div>
      <p style="margin:0;">Define one in Monitoring Rules to start raising alerts.</p>
    </div>
  <?php else: ?>
    <div class="compliance-table-wrap">
    <table class="cmp-table compliance-table">
      <thead><tr>
        <th>Code</th><th>Title</th><th>Kind</th><th>Severity</th><th>Active</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td class="cmp-mono"><?= h((string)$r['rule_code']) ?></td>
            <td><?= h((string)$r['title']) ?></td>
            <td><?= h((string)$r['monitor_kind']) ?></td>
            <td><span class="cmp-pill sev-<?= h((string)$r['alert_severity']) ?>"><?= h((string)$r['alert_severity']) ?></span></td>
            <td><?= ((int)$r['is_active'] === 1) ? 'Yes' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($runs !== array()): ?>
  <section class="cmp-card">
    <div class="cmp-card-head">
      <h2 class="cmp-card-title">Recent runs</h2>
    </div>
    <div class="compliance-table-wrap">
    <table class="cmp-table compliance-table">
      <thead><tr><th>Started</th><th>Status</th><th>Hits</th><th>Trigger</th></tr></thead>
      <tbody>
        <?php foreach ($runs as $r): ?>
          <tr>
            <td class="cmp-mono"><?= h(substr((string)($r['started_at'] ?? ''), 0, 16)) ?></td>
            <td><?= h((string)$r['run_status']) ?></td>
            <td><?= (int)($r['hit_count'] ?? 0) ?></td>
            <td class="cmp-mono"><?= h((string)($r['trigger_source'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
<?php endif; ?>

<?php
        compliance_page_close();
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
