<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsWorkflowService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsAuditService.php';

$user = compliance_require_access($pdo);
$actorId = (int)($user['id'] ?? 0);
$versionId = (int)($_GET['version_id'] ?? $_POST['version_id'] ?? 0);
$workflow = new BooksManualsWorkflowService($pdo);
$auditService = new BooksManualsAuditService($pdo);
$csrf = (string)($_SESSION['books_manuals_audit_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['books_manuals_audit_csrf'] = $csrf;
}

function bm_audit_flash(string $type, string $message): void
{
    $_SESSION['books_manuals_audit_flash'] = array('type' => $type, 'message' => $message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The form expired. Reload and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'refresh_integrity') {
            $runs = $auditService->startIntegrityRefresh($versionId, $actorId);
            bm_audit_flash('success', count($runs) . ' MCCF integrity job(s) queued.');
        } elseif ($action === 'snapshot') {
            $snapshot = $auditService->createSnapshot($versionId, $actorId);
            bm_audit_flash(
                $snapshot['status'] === 'passed' ? 'success' : 'error',
                'Immutable snapshot ' . (string)$snapshot['snapshot_uuid']
                    . ' recorded as ' . strtoupper((string)$snapshot['status']) . '.'
            );
        } else {
            throw new RuntimeException('Unknown audit action.');
        }
    } catch (Throwable $e) {
        bm_audit_flash('error', $e->getMessage());
    }
    redirect('/admin/compliance/books_manuals_audits.php?version_id=' . $versionId);
}

$flash = $_SESSION['books_manuals_audit_flash'] ?? null;
unset($_SESSION['books_manuals_audit_flash']);
$detail = $versionId > 0 ? $workflow->getVersionDetail($versionId) : null;
$coverage = null;
$coverageError = null;
if ($detail !== null) {
    try {
        $coverage = $auditService->liveCoverage($versionId);
    } catch (Throwable $e) {
        $coverageError = $e->getMessage();
    }
}
$snapshots = $detail !== null ? $auditService->listSnapshots($versionId) : array();
$library = $workflow->tablesPresent() ? $workflow->listLibrary(false) : array();

cw_header('Compliance · Books & Manuals Audit');
echo '<link rel="stylesheet" href="/assets/books-manuals.css?v=1">';
compliance_page_open(array(
    'title' => 'Books & Manuals Audit',
    'description' => 'Compliance-owned MCCF coverage, source-baseline and authoritative-pagination evidence.',
    'flash' => is_array($flash) ? $flash : null,
    'stats' => $coverage !== null ? array(
        array('label' => 'Coverage', 'value' => number_format((float)$coverage['coverage_percent'], 1) . '%'),
        array('label' => 'Missing', 'value' => (int)$coverage['missing_count'], 'tone' => (int)$coverage['missing_count'] > 0 ? 'crit' : 'ok'),
        array('label' => 'Insufficient', 'value' => (int)$coverage['insufficient_count'], 'tone' => (int)$coverage['insufficient_count'] > 0 ? 'warn' : 'ok'),
    ) : array(),
));
?>

<section class="cmp-card" style="margin-bottom:16px;">
  <form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
    <label style="display:grid;gap:6px;min-width:300px;">
      <span>Manual revision</span>
      <select name="version_id" required>
        <option value="">Select a manual…</option>
        <?php foreach ($library as $row): ?>
          <option value="<?= (int)$row['version_id'] ?>" <?= (int)$row['version_id'] === $versionId ? 'selected' : '' ?>>
            <?= h((string)$row['book_key']) ?> <?= h((string)$row['version_label']) ?> — <?= h((string)$row['phase_label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="app-btn app-btn--primary" type="submit">Open audit</button>
  </form>
</section>

<?php if ($detail !== null): ?>
  <section class="cmp-card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;"><?= h((string)$detail['book_title']) ?> · <?= h((string)$detail['version_label']) ?></h2>
    <?php if ($coverageError !== null): ?>
      <div class="cmp-alert cmp-alert--error"><?= h($coverageError) ?></div>
    <?php elseif ($coverage !== null): ?>
      <div class="bm-progress"><span style="width:<?= h(number_format((float)$coverage['coverage_percent'], 2, '.', '')) ?>%"></span></div>
      <dl class="bm-meta">
        <dt>Required items</dt><dd><?= (int)$coverage['total_count'] ?></dd>
        <dt>Covered</dt><dd><?= (int)$coverage['covered_count'] ?></dd>
        <dt>Source baseline</dt><dd><?= !empty($coverage['source_baseline_ok']) ? 'PASS' : 'FAIL' ?></dd>
        <dt>Pagination</dt><dd><?= !empty($coverage['authoritative_pagination_ok']) ? 'PASS' : 'FAIL' ?></dd>
      </dl>
      <?php foreach ((array)$coverage['foundation_errors'] as $error): ?>
        <div class="cmp-alert cmp-alert--error" style="margin-top:8px;"><?= h((string)$error) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="bm-actions" style="margin-top:16px;">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="version_id" value="<?= $versionId ?>">
        <input type="hidden" name="action" value="refresh_integrity">
        <button class="app-btn app-btn--secondary" type="submit">Refresh MCCF scores</button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="version_id" value="<?= $versionId ?>">
        <input type="hidden" name="action" value="snapshot">
        <button class="app-btn app-btn--primary" type="submit">Create immutable snapshot</button>
      </form>
      <a class="app-btn app-btn--secondary" href="/admin/books_manuals/index.php?open=<?= $versionId ?>">Manual settings</a>
    </div>
  </section>

  <section class="cmp-card">
    <h2 style="margin-top:0;">Immutable audit history</h2>
    <?php if ($snapshots === array()): ?>
      <p>No snapshots yet.</p>
    <?php else: ?>
      <div style="overflow:auto;">
        <table class="cmp-table" style="width:100%;">
          <thead>
            <tr>
              <th align="left">Created</th>
              <th align="left">Snapshot</th>
              <th align="left">Route</th>
              <th align="left">Status</th>
              <th align="right">Coverage</th>
              <th align="right">Missing</th>
              <th align="right">Insufficient</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($snapshots as $snapshot): ?>
              <tr>
                <td><?= h((string)$snapshot['created_at']) ?></td>
                <td><code><?= h((string)$snapshot['snapshot_uuid']) ?></code></td>
                <td><?= h(strtoupper((string)$snapshot['audit_type'])) ?></td>
                <td><?= compliance_badge((string)$snapshot['status']) ?></td>
                <td align="right"><?= h(number_format((float)$snapshot['coverage_percent'], 1)) ?>%</td>
                <td align="right"><?= (int)$snapshot['missing_count'] ?></td>
                <td align="right"><?= (int)$snapshot['insufficient_count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php
compliance_page_close();
cw_footer();
