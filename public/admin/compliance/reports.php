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
?>
<style>
  .cmprep-h1{margin:0 0 6px;font-size:24px;color:#0f172a;}
  .cmprep-sub{margin:0 0 22px;color:#64748b;max-width:760px;line-height:1.55;}
  .cmprep-grid{display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;max-width:1200px;}
  @media (max-width:780px){.cmprep-grid{grid-template-columns:1fr;}}
  .cmprep-side{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px;}
  .cmprep-side a{
    display:block;padding:8px 10px;border-radius:8px;color:#0f172a;
    text-decoration:none;font-size:14px;font-weight:600;
  }
  .cmprep-side a:hover{background:#f1f5f9;}
  .cmprep-side a.is-on{background:#1e3c72;color:#fff;}
  .cmprep-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;}
  .cmprep-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cmprep-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
  }
  .cmprep-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmprep-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmprep-btn{
    background:#0f766e;color:#fff;border:0;padding:8px 14px;border-radius:8px;
    font-weight:800;cursor:pointer;text-decoration:none;display:inline-block;
  }
</style>

<section style="padding:8px 0 40px;">
  <h1 class="cmprep-h1">Compliance reports</h1>
  <p class="cmprep-sub">
    Standard aggregate views over the compliance dataset. Pick a report on the left, then download a
    CSV snapshot for the authority pack, board pack or your own analysis.
  </p>

  <div class="cmprep-grid">
    <nav class="cmprep-side">
      <?php foreach ($REPORTS as $k => $cfg): ?>
        <a href="/admin/compliance/reports.php?report=<?= urlencode($k) ?>"
           class="<?= $selected === $k ? 'is-on' : '' ?>"><?= h((string)$cfg['title']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="cmprep-card">
      <?php if ($selected === '' || !isset($REPORTS[$selected])): ?>
        <h2 style="margin:0 0 8px;font-size:18px;">Pick a report</h2>
        <p style="margin:0;color:#64748b;">Choose one of the reports on the left to see counts and download a CSV.</p>
      <?php
        else:
          $cfg = $REPORTS[$selected];
          $rows = rpt_rows($pdo, (string)$cfg['sql']);
          $mapFn = $cfg['map'];
      ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
          <div>
            <h2 style="margin:0 0 4px;font-size:18px;"><?= h((string)$cfg['title']) ?></h2>
            <p style="margin:0;color:#64748b;font-size:13px;"><?= h((string)$cfg['description']) ?></p>
          </div>
          <a class="cmprep-btn" href="/admin/compliance/reports.php?report=<?= urlencode($selected) ?>&export=csv">Download CSV</a>
        </div>
        <?php if ($rows === array()): ?>
          <p style="margin:0;color:#64748b;">No data to show.</p>
        <?php else: ?>
          <table class="cmprep-table">
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
    </div>
  </div>
</section>
<?php
cw_footer();
