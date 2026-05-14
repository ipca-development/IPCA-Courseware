<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

compliance_require_access($pdo);

/**
 * Compliance OS — landing dashboard.
 *
 * Pulls live counts directly from ipca_compliance_* tables. Each helper
 * swallows missing-table / missing-column errors so the dashboard still
 * renders cleanly on an environment that hasn't run a particular phase
 * migration yet.
 */

function cw_compliance_count(PDO $pdo, string $sql, array $args = array()): int
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);

        return (int)$st->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function cw_compliance_rows(PDO $pdo, string $sql, array $args = array()): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    } catch (Throwable) {
        return array();
    }
}

$openAudits = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_audits WHERE status NOT IN ('CLOSED','CANCELLED')"
);
$totalAudits = cw_compliance_count($pdo, 'SELECT COUNT(*) FROM ipca_compliance_audits');

$openFindings = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_findings WHERE COALESCE(status,'') NOT IN ('CLOSED','VOID')"
);
$totalFindings = cw_compliance_count($pdo, 'SELECT COUNT(*) FROM ipca_compliance_findings');

$openCaps = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')"
);
$overdueCaps = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_corrective_actions
      WHERE due_date IS NOT NULL AND due_date < CURDATE()
        AND COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')"
);

$openCrs = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_change_requests
      WHERE status NOT IN ('RELEASED','CANCELLED','REJECTED')"
);
$activeDrafts = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_drafts
      WHERE status IN ('DRAFT','UNDER_REVIEW','APPROVED')"
);
$openPackages = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_release_packages
      WHERE status NOT IN ('RELEASED','SUPERSEDED','CANCELLED')"
);

$openAlerts = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_alerts WHERE status = 'OPEN'"
);
$critAlerts = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_alerts WHERE status = 'OPEN' AND severity = 'CRITICAL'"
);

$openMocCases = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_cases
      WHERE case_type = 'MANAGEMENT_OF_CHANGE'
        AND status NOT IN ('CLOSED','CANCELLED')"
);
$openInbox = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_inbound_emails
      WHERE triage_state IN ('NEW','IN_REVIEW')"
);
$openMeetings = cw_compliance_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_meetings
      WHERE status IN ('SCHEDULED','LIVE')"
);

// Findings by severity / authority.
$findingsBySeverity = cw_compliance_rows(
    $pdo,
    "SELECT COALESCE(severity,'—') AS k, COUNT(*) AS n
       FROM ipca_compliance_findings
      WHERE COALESCE(status,'') NOT IN ('CLOSED','VOID')
      GROUP BY COALESCE(severity,'—')
      ORDER BY n DESC"
);
$findingsByAuthority = cw_compliance_rows(
    $pdo,
    "SELECT COALESCE(f.authority, a.authority, '—') AS k, COUNT(*) AS n
       FROM ipca_compliance_findings f
       LEFT JOIN ipca_compliance_audits a ON a.id = f.audit_id
      WHERE COALESCE(f.status,'') NOT IN ('CLOSED','VOID')
      GROUP BY k
      ORDER BY n DESC
      LIMIT 8"
);

// Upcoming due CAPs (next 30 days, open).
$upcomingCaps = cw_compliance_rows(
    $pdo,
    "SELECT id, action_code, title, due_date, status, severity
       FROM ipca_compliance_corrective_actions
      WHERE due_date IS NOT NULL
        AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')
      ORDER BY due_date ASC
      LIMIT 10"
);

// Recent compliance activity from case_events (if populated).
$recentEvents = cw_compliance_rows(
    $pdo,
    "SELECT entity_type, entity_id, event_kind, summary, occurred_at
       FROM ipca_compliance_case_events
      ORDER BY occurred_at DESC
      LIMIT 12"
);

// Recent audits (last touched).
$recentAudits = cw_compliance_rows(
    $pdo,
    "SELECT id, audit_code, title, status, authority, updated_at
       FROM ipca_compliance_audits
      ORDER BY updated_at DESC, id DESC
      LIMIT 6"
);

// Recent open alerts.
$recentAlerts = cw_compliance_rows(
    $pdo,
    "SELECT id, alert_kind, severity, title, raised_at
       FROM ipca_compliance_alerts
      WHERE status = 'OPEN'
      ORDER BY raised_at DESC
      LIMIT 6"
);

cw_header('Compliance · Dashboard');

compliance_page_open(array(
    'overline' => 'Compliance Operations',
    'title' => 'Compliance dashboard',
    'description' => 'Live view of the compliance posture across audits, findings, corrective actions, manual control and monitoring alerts. Every tile drills through to its detail workspace.',
    'actions' => array(
        array('label' => 'New finding', 'href' => '/admin/compliance/findings.php', 'icon' => 'plus'),
        array('label' => 'Run monitor sweep', 'href' => '/admin/compliance/live_monitoring.php', 'icon' => 'pulse'),
        array('label' => 'Open inbox', 'href' => '/admin/compliance/inbox.php', 'icon' => 'inbox'),
    ),
    'stats' => array(
        array('label' => 'Open audits',     'value' => (int)$openAudits,    'sub' => (int)$totalAudits . ' total',                                                              'href' => '/admin/compliance/audits.php'),
        array('label' => 'Open findings',   'value' => (int)$openFindings,  'sub' => (int)$totalFindings . ' total',                                                            'href' => '/admin/compliance/findings.php'),
        array('label' => 'Open CAPs',       'value' => (int)$openCaps,      'sub' => $overdueCaps > 0 ? ((int)$overdueCaps . ' overdue') : 'on track', 'tone' => $overdueCaps > 0 ? 'warn' : 'ok',  'href' => '/admin/compliance/corrective_actions.php'),
        array('label' => 'Open alerts',     'value' => (int)$openAlerts,    'sub' => $critAlerts > 0 ? ((int)$critAlerts . ' critical') : 'monitoring', 'tone' => $critAlerts > 0 ? 'crit' : ($openAlerts > 0 ? 'warn' : 'ok'), 'href' => '/admin/compliance/live_monitoring.php'),
        array('label' => 'Change requests', 'value' => (int)$openCrs,       'sub' => 'in-flight',                                                                               'href' => '/admin/compliance/change_requests.php'),
        array('label' => 'Manual drafts',   'value' => (int)$activeDrafts,  'sub' => 'active',                                                                                  'href' => '/admin/compliance/manual_drafts.php'),
        array('label' => 'Release pkgs',    'value' => (int)$openPackages,  'sub' => 'not yet released',                                                                        'href' => '/admin/compliance/manual_approved.php'),
        array('label' => 'MoC cases',       'value' => (int)$openMocCases,  'sub' => 'open',                                                                                    'href' => '/admin/compliance/moc.php'),
        array('label' => 'Inbox',           'value' => (int)$openInbox,     'sub' => 'awaiting triage',                                                                         'href' => '/admin/compliance/inbox.php'),
        array('label' => 'Meetings',        'value' => (int)$openMeetings,  'sub' => 'scheduled / live',                                                                        'href' => '/admin/compliance/meetings.php'),
    ),
));
?>
<style>
  .cmpdash-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));
    gap:18px;align-items:start;margin-bottom:18px;
  }
  .cmpdash-bar{
    display:flex;align-items:center;gap:10px;font-size:13px;margin-bottom:8px;
  }
  .cmpdash-bar-k{width:130px;color:var(--text-strong);font-weight:680;}
  .cmpdash-bar-track{flex:1;background:#eef2f7;border-radius:999px;height:8px;overflow:hidden;}
  .cmpdash-bar-fill{display:block;height:100%;background:linear-gradient(90deg,#17345d,#3a6fd0);border-radius:999px;}
  .cmpdash-bar-n{width:42px;text-align:right;color:var(--text-muted);font-weight:680;font-size:12px;}
  .cmpdash-section-link{
    margin-top:14px;display:inline-block;font-weight:680;color:#1f4079;text-decoration:none;
    border-bottom:1px solid transparent;transition:border-color .16s ease;
  }
  .cmpdash-section-link:hover{border-color:rgba(31,64,121,0.35);}
  .cmpdash-empty{color:var(--text-muted);font-size:13.5px;margin:0;}
</style>

<div class="cmpdash-grid">
    <div class="cmpdash-panel">
      <h2>Open findings by severity</h2>
      <?php
      $maxN = 1;
      foreach ($findingsBySeverity as $r) {
          if ((int)$r['n'] > $maxN) {
              $maxN = (int)$r['n'];
          }
      }
      ?>
      <?php if ($findingsBySeverity === array()): ?>
        <p class="cmpdash-empty">No open findings.</p>
      <?php else: ?>
        <?php foreach ($findingsBySeverity as $r):
          $k = (string)$r['k'];
          $n = (int)$r['n'];
          $w = (int)round(($n / $maxN) * 100);
        ?>
          <div class="cmpdash-bar">
            <div class="cmpdash-bar-k"><?= h($k) ?></div>
            <div class="cmpdash-bar-track"><span class="cmpdash-bar-fill" style="width:<?= $w ?>%;"></span></div>
            <div class="cmpdash-bar-n"><?= $n ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <a class="cmpdash-section-link" href="/admin/compliance/findings.php">All findings →</a>
    </div>

    <div class="cmpdash-panel">
      <h2>Open findings by authority</h2>
      <?php
      $maxA = 1;
      foreach ($findingsByAuthority as $r) {
          if ((int)$r['n'] > $maxA) {
              $maxA = (int)$r['n'];
          }
      }
      ?>
      <?php if ($findingsByAuthority === array()): ?>
        <p class="cmpdash-empty">No data.</p>
      <?php else: ?>
        <?php foreach ($findingsByAuthority as $r):
          $k = (string)$r['k'];
          $n = (int)$r['n'];
          $w = (int)round(($n / $maxA) * 100);
        ?>
          <div class="cmpdash-bar">
            <div class="cmpdash-bar-k"><?= h($k) ?></div>
            <div class="cmpdash-bar-track"><span class="cmpdash-bar-fill" style="width:<?= $w ?>%;background:#3730a3;"></span></div>
            <div class="cmpdash-bar-n"><?= $n ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="cmpdash-panel">
      <h2>CAPs due in the next 30 days</h2>
      <?php if ($upcomingCaps === array()): ?>
        <p class="cmpdash-empty">Nothing due in the next month.</p>
      <?php else: ?>
        <div class="compliance-table-wrap">
        <table class="cmpdash-table compliance-table">
          <thead><tr>
            <th>Code</th><th>Title</th><th>Due</th><th>Sev</th>
          </tr></thead>
          <tbody>
            <?php foreach ($upcomingCaps as $r): ?>
              <tr>
                <td class="cmpdash-mono">
                  <a href="/admin/compliance/corrective_actions.php?id=<?= (int)$r['id'] ?>"
                     style="color:#1e3c72;font-weight:700;text-decoration:none;">
                    <?= h((string)$r['action_code']) ?>
                  </a>
                </td>
                <td><?= h((string)($r['title'] ?? '')) ?></td>
                <td class="cmpdash-mono"><?= h(substr((string)($r['due_date'] ?? ''), 0, 10)) ?></td>
                <td>
                  <?php $sev = (string)($r['severity'] ?? ''); if ($sev !== ''): ?>
                    <span class="cmpdash-pill sev-<?= h($sev) ?>"><?= h($sev) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
      <a class="cmpdash-section-link" href="/admin/compliance/cap_monitoring.php">CAP monitoring →</a>
    </div>

    <div class="cmpdash-panel">
      <h2>Recently touched audits</h2>
      <?php if ($recentAudits === array()): ?>
        <p class="cmpdash-empty">No audits on file yet.</p>
      <?php else: ?>
        <div class="compliance-table-wrap">
        <table class="cmpdash-table compliance-table">
          <thead><tr>
            <th>Code</th><th>Title</th><th>Status</th><th>Authority</th>
          </tr></thead>
          <tbody>
            <?php foreach ($recentAudits as $r): ?>
              <tr>
                <td class="cmpdash-mono">
                  <a href="/admin/compliance/audits.php?id=<?= (int)$r['id'] ?>"
                     style="color:#1e3c72;font-weight:700;text-decoration:none;">
                    <?= h((string)$r['audit_code']) ?>
                  </a>
                </td>
                <td><?= h((string)$r['title']) ?></td>
                <td><?= h((string)$r['status']) ?></td>
                <td><?= h((string)$r['authority']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
      <a class="cmpdash-section-link" href="/admin/compliance/audits.php">All audits →</a>
    </div>

    <div class="cmpdash-panel">
      <h2>Latest open alerts</h2>
      <?php if ($recentAlerts === array()): ?>
        <p class="cmpdash-empty">No open alerts.</p>
      <?php else: ?>
        <div class="compliance-table-wrap">
        <table class="cmpdash-table compliance-table">
          <thead><tr>
            <th>Sev</th><th>Title</th><th>Kind</th><th>Raised</th>
          </tr></thead>
          <tbody>
            <?php foreach ($recentAlerts as $r): ?>
              <tr>
                <td>
                  <?php $sev = (string)($r['severity'] ?? ''); if ($sev !== ''): ?>
                    <span class="cmpdash-pill sev-<?= h($sev) ?>"><?= h($sev) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= h((string)$r['title']) ?></td>
                <td class="cmpdash-mono"><?= h((string)$r['alert_kind']) ?></td>
                <td class="cmpdash-mono"><?= h(substr((string)($r['raised_at'] ?? ''), 0, 16)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
      <a class="cmpdash-section-link" href="/admin/compliance/live_monitoring.php">Live monitoring →</a>
    </div>

    <div class="cmpdash-panel">
      <h2>Recent activity</h2>
      <?php if ($recentEvents === array()): ?>
        <p class="cmpdash-empty">No recorded events yet.</p>
      <?php else: ?>
        <ul style="margin:0;padding:0;list-style:none;font-size:13px;">
          <?php foreach ($recentEvents as $r): ?>
            <li style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
              <span class="cmpdash-mono" style="color:#64748b;"><?= h(substr((string)($r['occurred_at'] ?? ''), 0, 16)) ?></span>
              · <strong><?= h((string)$r['event_kind']) ?></strong>
              <span style="color:#64748b;">on <?= h((string)$r['entity_type']) ?>
                <?php if (!empty($r['entity_id'])): ?>#<?= (int)$r['entity_id'] ?><?php endif; ?>
              </span>
              <?php if (!empty($r['summary'])): ?>
                <div style="color:#334155;"><?= h((string)$r['summary']) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

<?php
compliance_page_close();
cw_footer();
