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
$complianceOverrides = $detail !== null
    ? $auditService->listComplianceOverrides($versionId)
    : array();
$library = $workflow->tablesPresent() ? $workflow->listLibrary(false) : array();
$gapFilter = strtolower(trim((string)($_GET['gap'] ?? 'all')));
if (!in_array($gapFilter, array('all', 'missing', 'insufficient'), true)) {
    $gapFilter = 'all';
}
$gapRequirements = array();
if (is_array($coverage)) {
    foreach ((array)($coverage['requirements'] ?? array()) as $requirement) {
        $state = (string)($requirement['coverage_state'] ?? '');
        if (!in_array($state, array('missing', 'insufficient'), true)) {
            continue;
        }
        if ($gapFilter !== 'all' && $gapFilter !== $state) {
            continue;
        }
        $gapRequirements[] = $requirement;
    }
    usort($gapRequirements, static function (array $a, array $b): int {
        $stateOrder = array('missing' => 0, 'insufficient' => 1);
        $state = ($stateOrder[(string)($a['coverage_state'] ?? '')] ?? 9)
            <=> ($stateOrder[(string)($b['coverage_state'] ?? '')] ?? 9);
        if ($state !== 0) {
            return $state;
        }
        $scoreA = $a['score'] === null ? -1 : (int)$a['score'];
        $scoreB = $b['score'] === null ? -1 : (int)$b['score'];
        return ($scoreA <=> $scoreB)
            ?: strnatcasecmp(
                (string)($a['requirement_key'] ?? ''),
                (string)($b['requirement_key'] ?? '')
            );
    });
}

cw_header('Compliance · Books & Manuals Audit');
$booksManualsCssVersion = @filemtime(__DIR__ . '/../../assets/books-manuals.css') ?: time();
echo '<link rel="stylesheet" href="/assets/books-manuals.css?v=' . (int)$booksManualsCssVersion . '">';
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

  <?php if ($coverage !== null && (array)($coverage['required_source_sets'] ?? array()) !== array()): ?>
    <section class="cmp-card bm-bcaa-submission" style="margin-bottom:16px;">
      <div class="bm-bcaa-submission__intro">
        <div>
          <div class="bm-audit-gap__eyebrow">Authority submission view</div>
          <h2>BCAA MCCF version</h2>
          <p>
            BCAA-format checklist for
            <strong><?= h((string)$detail['book_key']) ?> <?= h((string)$detail['version_label']) ?></strong>.
          </p>
        </div>
        <span class="bm-bcaa-readiness <?= !empty($coverage['passed']) ? 'is-ready' : 'is-blocked' ?>">
          <?= !empty($coverage['passed']) ? 'SUBMISSION READY' : 'NOT READY' ?>
        </span>
      </div>

      <div class="bm-bcaa-source-list">
        <?php foreach ((array)$coverage['required_source_sets'] as $sourceSet): ?>
          <?php
            $sourceSetId = (int)($sourceSet['source_set_id'] ?? 0);
            $bcaaUrl = '/admin/compliance/mccf_browser.php?'
                . http_build_query(array(
                    'source_set_id' => $sourceSetId,
                    'layout' => 'bcaa',
                ));
            $sourceHash = trim((string)($sourceSet['source_hash'] ?? ''));
          ?>
          <article class="bm-bcaa-source">
            <div>
              <strong><?= h((string)($sourceSet['source_set_key'] ?? 'MCCF')) ?></strong>
              <span>
                Source revision <?= h((string)($sourceSet['revision_label'] ?? 'Not labelled')) ?>
                <?php if ($sourceHash !== ''): ?>
                  · <?= h(substr($sourceHash, 0, 12)) ?>…
                <?php endif; ?>
              </span>
            </div>
            <a class="app-btn app-btn--primary" href="<?= h($bcaaUrl) ?>">View BCAA Submission MCCF</a>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (empty($coverage['passed'])): ?>
        <p class="bm-bcaa-submission__warning">
          This is the current BCAA-format candidate, but it must not be submitted yet:
          <?= (int)$coverage['missing_count'] ?> missing and
          <?= (int)$coverage['insufficient_count'] ?> insufficient requirements remain,
          and all release checks must pass.
        </p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($coverage !== null): ?>
    <section class="cmp-card bm-audit-gaps" style="margin-bottom:16px;">
      <div class="bm-audit-gaps__head">
        <div>
          <h2>MCCF items requiring attention</h2>
          <p>Open an item to compare the regulation requirement with its linked manual coverage and evidence.</p>
        </div>
        <div class="bm-audit-gap-filters" aria-label="Coverage gap filters">
          <a class="<?= $gapFilter === 'all' ? 'is-active' : '' ?>" href="?version_id=<?= $versionId ?>&amp;gap=all">
            All <?= (int)$coverage['missing_count'] + (int)$coverage['insufficient_count'] ?>
          </a>
          <a class="<?= $gapFilter === 'missing' ? 'is-active' : '' ?>" href="?version_id=<?= $versionId ?>&amp;gap=missing">
            Missing <?= (int)$coverage['missing_count'] ?>
          </a>
          <a class="<?= $gapFilter === 'insufficient' ? 'is-active' : '' ?>" href="?version_id=<?= $versionId ?>&amp;gap=insufficient">
            Insufficient <?= (int)$coverage['insufficient_count'] ?>
          </a>
        </div>
      </div>

      <?php if ($gapRequirements === array()): ?>
        <div class="bm-audit-gaps__empty">No requirements match this filter.</div>
      <?php else: ?>
        <div class="bm-audit-gap-list">
          <?php foreach ($gapRequirements as $requirement): ?>
            <?php
              $state = (string)$requirement['coverage_state'];
              $score = $requirement['score'] === null ? null : (int)$requirement['score'];
              $reasons = is_array($requirement['reasons'] ?? null)
                  ? array_values(array_filter(array_map('strval', $requirement['reasons'])))
                  : array();
              $mccfUrl = '/admin/compliance/mccf_browser.php?'
                  . http_build_query(array(
                      'source_set_id' => (int)$requirement['source_set_id'],
                      'req' => (int)$requirement['id'],
                      'layout' => 'bcaa',
                  ));
            ?>
            <article class="bm-audit-gap bm-audit-gap--<?= h($state) ?>">
              <div class="bm-audit-gap__status">
                <span><?= h(strtoupper($state)) ?></span>
                <strong><?= $score === null ? 'Not scored' : $score . '%' ?></strong>
              </div>
              <div class="bm-audit-gap__copy">
                <div class="bm-audit-gap__eyebrow">
                  <?= h((string)($requirement['requirement_key'] ?? 'MCCF item')) ?>
                  <?php if (trim((string)($requirement['manual_part'] ?? '')) !== ''): ?>
                    · <?= h((string)$requirement['manual_part']) ?>
                  <?php endif; ?>
                </div>
                <h3><?= h((string)($requirement['subject'] ?? 'Requirement')) ?></h3>
                <dl>
                  <dt>Regulation</dt>
                  <dd><?= h((string)($requirement['regulation_ref'] ?? 'Not linked')) ?></dd>
                  <dt>Manual reference</dt>
                  <dd><?= h((string)($requirement['manual_section_ref'] ?? 'Not linked')) ?></dd>
                </dl>
                <?php if ($reasons !== array()): ?>
                  <ul>
                    <?php foreach (array_slice($reasons, 0, 3) as $reason): ?>
                      <li><?= h($reason) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php elseif ($score === null): ?>
                  <p class="bm-audit-gap__reason">No MCCF integrity score is available. Refresh MCCF scores first.</p>
                <?php endif; ?>
              </div>
              <a class="app-btn app-btn--secondary bm-audit-gap__action" href="<?= h($mccfUrl) ?>">Open in MCCF</a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($complianceOverrides !== array()): ?>
    <section class="cmp-card bm-audit-overrides" style="margin-bottom:16px;">
      <div class="bm-audit-gap__eyebrow">Immutable governance record</div>
      <h2>Compliance overrides</h2>
      <p class="bm-audit-overrides__intro">
        These records preserve who overrode the release blockers, why they did
        so, and the exact blocker state at that moment.
      </p>
      <div class="bm-audit-override-list">
        <?php foreach ($complianceOverrides as $override): ?>
          <?php $blockers = (array)($override['blockers'] ?? array()); ?>
          <article class="bm-audit-override">
            <div class="bm-audit-override__head">
              <strong>ALL RELEASE BLOCKERS OVERRIDDEN</strong>
              <span><?= h((string)$override['created_at']) ?></span>
            </div>
            <blockquote><?= nl2br(h((string)$override['rationale'])) ?></blockquote>
            <dl>
              <dt>Recorded by</dt>
              <dd><?= h((string)($override['actor_name'] ?: $override['actor_email'] ?: 'Unknown administrator')) ?></dd>
              <dt>Previous stage</dt>
              <dd><?= h(strtoupper((string)($blockers['previous_lifecycle_status'] ?? 'unknown'))) ?></dd>
              <dt>MCCF blockers</dt>
              <dd>
                <?= (int)($blockers['missing_count'] ?? 0) ?> missing ·
                <?= (int)($blockers['insufficient_count'] ?? 0) ?> insufficient
              </dd>
              <dt>Foundation blockers</dt>
              <dd>
                Source baseline <?= !empty($blockers['source_baseline_ok']) ? 'ready' : 'overridden' ?> ·
                Pagination <?= !empty($blockers['authoritative_pagination_ok']) ? 'ready' : 'overridden' ?>
              </dd>
              <dt>Source fingerprint</dt>
              <dd><code><?= h((string)$override['source_fingerprint']) ?></code></dd>
              <dt>Override ID</dt>
              <dd><code><?= h((string)$override['override_uuid']) ?></code></dd>
            </dl>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

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
