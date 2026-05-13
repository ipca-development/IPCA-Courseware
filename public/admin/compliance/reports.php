<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';

compliance_require_access($pdo);

/**
 * Compliance Reports — aggregate views with one-click CSV export.
 *
 * Available reports (passed via ?report=):
 *   - findings_by_authority
 *   - findings_by_severity
 *   - caps_by_status
 *   - caps_overdue
 *   - audits_by_status
 *   - alerts_by_kind
 *   - manual_release_summary
 *
 * Same page handles both HTML view and ?export=csv download.
 */

function rpt_rows(PDO $pdo, string $sql, array $args = array()): array
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

$REPORTS = array(
    'findings_by_authority' => array(
        'title' => 'Findings by authority',
        'description' => 'Counts findings (open vs closed) grouped by authority/regulator.',
        'columns' => array('Authority', 'Open', 'Closed', 'Total'),
        'sql' => "SELECT
                    COALESCE(f.authority, a.authority, '—') AS authority,
                    SUM(CASE WHEN COALESCE(f.status,'') NOT IN ('CLOSED','VOID','CANCELLED') THEN 1 ELSE 0 END) AS open_n,
                    SUM(CASE WHEN COALESCE(f.status,'') IN ('CLOSED','VOID','CANCELLED') THEN 1 ELSE 0 END) AS closed_n,
                    COUNT(*) AS total_n
                  FROM ipca_compliance_findings f
             LEFT JOIN ipca_compliance_audits a ON a.id = f.audit_id
                 GROUP BY authority
                 ORDER BY total_n DESC",
        'map' => static fn(array $r) => array(
            (string)$r['authority'], (int)$r['open_n'], (int)$r['closed_n'], (int)$r['total_n'],
        ),
    ),
    'findings_by_severity' => array(
        'title' => 'Findings by severity',
        'description' => 'Counts open findings grouped by severity bucket.',
        'columns' => array('Severity', 'Count'),
        'sql' => "SELECT COALESCE(severity,'—') AS k, COUNT(*) AS n
                    FROM ipca_compliance_findings
                   WHERE COALESCE(status,'') NOT IN ('CLOSED','VOID','CANCELLED')
                   GROUP BY COALESCE(severity,'—')
                   ORDER BY FIELD(k,'CRITICAL','HIGH','MEDIUM','LOW','—')",
        'map' => static fn(array $r) => array((string)$r['k'], (int)$r['n']),
    ),
    'caps_by_status' => array(
        'title' => 'CAPs by status',
        'description' => 'Corrective actions grouped by current workflow status.',
        'columns' => array('Status', 'Count'),
        'sql' => "SELECT COALESCE(status,'—') AS k, COUNT(*) AS n
                    FROM ipca_compliance_corrective_actions
                   GROUP BY COALESCE(status,'—')
                   ORDER BY n DESC",
        'map' => static fn(array $r) => array((string)$r['k'], (int)$r['n']),
    ),
    'caps_overdue' => array(
        'title' => 'CAPs overdue',
        'description' => 'Every corrective action past its due_date and not yet closed.',
        'columns' => array('Code', 'Title', 'Due', 'Status', 'Severity'),
        'sql' => "SELECT ca.action_code, ca.title, ca.due_date, ca.status, f.severity
                    FROM ipca_compliance_corrective_actions ca
               LEFT JOIN ipca_compliance_findings f ON f.id = ca.finding_id
                   WHERE ca.due_date IS NOT NULL
                     AND ca.due_date < CURDATE()
                     AND COALESCE(ca.status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')
                   ORDER BY ca.due_date ASC
                   LIMIT 500",
        'map' => static fn(array $r) => array(
            (string)$r['action_code'], (string)$r['title'],
            (string)($r['due_date'] ?? ''), (string)$r['status'], (string)($r['severity'] ?? ''),
        ),
    ),
    'audits_by_status' => array(
        'title' => 'Audits by status',
        'description' => 'Audits grouped by status to surface where the queue is sitting.',
        'columns' => array('Status', 'Count'),
        'sql' => "SELECT status AS k, COUNT(*) AS n
                    FROM ipca_compliance_audits
                   GROUP BY status
                   ORDER BY FIELD(k,'PLANNED','SCHEDULED','IN_PROGRESS','FIELDWORK_COMPLETE','REPORT_DRAFT','REPORT_ISSUED','WAITING_AUTHORITY','CLOSED','CANCELLED')",
        'map' => static fn(array $r) => array((string)$r['k'], (int)$r['n']),
    ),
    'alerts_by_kind' => array(
        'title' => 'Alerts by monitor kind',
        'description' => 'Open compliance alerts grouped by their owning monitor kind.',
        'columns' => array('Monitor kind', 'Open', 'Critical (open)'),
        'sql' => "SELECT COALESCE(r.monitor_kind,'—') AS k,
                         SUM(CASE WHEN a.status='OPEN' THEN 1 ELSE 0 END) AS open_n,
                         SUM(CASE WHEN a.status='OPEN' AND a.severity='CRITICAL' THEN 1 ELSE 0 END) AS crit_n
                    FROM ipca_compliance_alerts a
               LEFT JOIN ipca_compliance_monitor_rules r ON r.id = a.rule_id
                   GROUP BY COALESCE(r.monitor_kind,'—')
                   ORDER BY open_n DESC",
        'map' => static fn(array $r) => array((string)$r['k'], (int)$r['open_n'], (int)$r['crit_n']),
    ),
    'manual_release_summary' => array(
        'title' => 'Manual release packages',
        'description' => 'Release packages with status, effective date and approver.',
        'columns' => array('Code', 'Title', 'Status', 'Effective date', 'Approved by'),
        'sql' => "SELECT package_code, title, status, effective_date,
                         COALESCE(approved_by_name,'—') AS approver
                    FROM ipca_compliance_manual_release_packages
                   ORDER BY COALESCE(effective_date, created_at) DESC
                   LIMIT 500",
        'map' => static fn(array $r) => array(
            (string)$r['package_code'], (string)$r['title'], (string)$r['status'],
            (string)($r['effective_date'] ?? ''), (string)$r['approver'],
        ),
    ),
);

$selected = (string)($_GET['report'] ?? '');
$export = (string)($_GET['export'] ?? '');

if ($selected !== '' && isset($REPORTS[$selected]) && $export === 'csv') {
    $cfg = $REPORTS[$selected];
    $rows = rpt_rows($pdo, (string)$cfg['sql']);
    $fname = 'compliance_' . $selected . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        exit;
    }
    fputcsv($fp, $cfg['columns']);
    $mapFn = $cfg['map'];
    foreach ($rows as $r) {
        fputcsv($fp, $mapFn($r));
    }
    fclose($fp);
    exit;
}

cw_header('Compliance · Reports');

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

compliance_page_open(array(
    'overline' => 'Compliance · Reports',
    'title' => 'Compliance reports',
    'description' => 'Standard aggregate views over the compliance dataset. Pick a report on the left, then download a CSV snapshot for the authority pack, board pack or your own analysis.',
    'stats' => array(
        array('label' => 'Reports available', 'value' => count($REPORTS)),
    ),
));
?>
<style>
  .cmprep-side{background:#fff;border:1px solid var(--border-soft);border-radius:14px;padding:14px;box-shadow:0 6px 16px rgba(15,28,47,0.04);}
  .cmprep-side a{display:block;padding:10px 12px;border-radius:10px;color:#0f172a;text-decoration:none;font-size:14px;font-weight:620;}
  .cmprep-side a:hover{background:var(--row-hover);}
  .cmprep-side a.is-on{background:linear-gradient(120deg,#1f4079 0%,#307cb7 100%);color:#fff;}
</style>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start;">
  <nav class="cmprep-side">
    <?php foreach ($REPORTS as $k => $cfg): ?>
      <a href="/admin/compliance/reports.php?report=<?= urlencode($k) ?>"
         class="<?= $selected === $k ? 'is-on' : '' ?>"><?= h((string)$cfg['title']) ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="cmp-card">
    <?php if ($selected === '' || !isset($REPORTS[$selected])): ?>
      <h2 style="margin:0 0 8px;">Pick a report</h2>
      <p style="margin:0;color:var(--text-muted);">Choose one of the reports on the left to see counts and download a CSV.</p>
    <?php
      else:
        $cfg = $REPORTS[$selected];
        $rows = rpt_rows($pdo, (string)$cfg['sql']);
        $mapFn = $cfg['map'];
    ?>
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div>
          <div class="cmp-list-title">
            <?= compliance_ui_icon('document') ?>
            <span><?= h((string)$cfg['title']) ?></span>
          </div>
          <p style="margin:6px 0 0;color:var(--text-muted);font-size:13px;"><?= h((string)$cfg['description']) ?></p>
        </div>
        <a class="cmp-btn-link" href="/admin/compliance/reports.php?report=<?= urlencode($selected) ?>&export=csv" style="text-decoration:none;">Download CSV</a>
      </div>
      <?php if ($rows === array()): ?>
        <p style="margin:0;color:var(--text-muted);">No data to show.</p>
      <?php else: ?>
        <table>
          <thead><tr>
            <?php foreach ($cfg['columns'] as $c): ?>
              <th><?= h((string)$c) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r):
              $vals = $mapFn($r); ?>
              <tr>
                <?php foreach ($vals as $v): ?>
                  <td><?= h((string)$v) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php
compliance_page_close();
cw_footer();
