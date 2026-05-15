<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceFindingEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceRcaCapEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCapEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceRcaCapSubmissionEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceDeadlineExtensionEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceRegulatoryLinkEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * @param 'success'|'error' $type
 */
function cmp_flash_set(string $type, string $message): void
{
    $_SESSION['_ipca_compliance_flash'] = array(
        'type' => $type,
        'message' => $message,
    );
}

/**
 * @return array{type:string,message:string}|null
 */
function cmp_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash']) || !is_array($_SESSION['_ipca_compliance_flash'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash'];
    unset($_SESSION['_ipca_compliance_flash']);
    return $f;
}

/**
 * @return list<array{whyNumber:int,question:string,answer:string}>
 */
function cmp_rca_steps_from_post(): array
{
    $steps = array();
    for ($i = 1; $i <= 5; $i++) {
        $q = trim((string)($_POST['why_' . $i . '_q'] ?? ''));
        $a = trim((string)($_POST['why_' . $i . '_a'] ?? ''));
        if ($q === '' && $a === '') {
            continue;
        }
        $steps[] = array(
            'whyNumber' => $i,
            'question' => $q,
            'answer' => $a,
        );
    }
    return ComplianceRcaCapEngine::normaliseSteps($steps);
}

function cmp_finding_target_date(PDO $pdo, int $findingId, ?string $fallback): ?string
{
    $date = ComplianceRcaCapSubmissionEngine::approvedCapDeadlineForFinding($pdo, $findingId);
    if ($date !== null) {
        return $date;
    }
    $fallback = $fallback !== null ? trim($fallback) : '';
    return $fallback !== '' ? substr($fallback, 0, 10) : null;
}

function cmp_finding_target_date_display(?string $date): string
{
    $date = $date !== null ? trim($date) : '';
    if ($date === '') {
        return '<span style="color:var(--text-muted);">—</span>';
    }
    try {
        $today = new DateTimeImmutable('today');
        $target = new DateTimeImmutable(substr($date, 0, 10));
        $class = $target < $today ? 'compliance-badge--deadline-expired' : 'compliance-badge--deadline-ok';
        return '<div class="cmp-list-deadline">'
            . '<span class="cmp-pill compliance-badge ' . $class . '">' . h(substr($date, 0, 10)) . '</span>'
            . '</div>';
    } catch (Throwable) {
        return '<span class="cmp-mono">' . h($date) . '</span>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_finding') {
            $auditRaw = trim((string)($_POST['audit_id'] ?? ''));
            $auditId = $auditRaw !== '' ? (int)$auditRaw : null;
            if ($auditId !== null && $auditId <= 0) {
                $auditId = null;
            }

            $id = ComplianceFindingEngine::create($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'classification' => (string)($_POST['classification'] ?? 'LEVEL_3'),
                'severity' => (string)($_POST['severity'] ?? 'MEDIUM'),
                'status' => (string)($_POST['status'] ?? 'OPEN'),
                'reference' => (string)($_POST['reference'] ?? ''),
                'raised_date' => (string)($_POST['raised_date'] ?? ''),
                'target_date' => (string)($_POST['target_date'] ?? ''),
                'regulation_summary' => (string)($_POST['regulation_summary'] ?? ''),
                'notes' => (string)($_POST['notes'] ?? ''),
                'domain_code' => (string)($_POST['domain_code'] ?? ''),
                'requirement_key' => (string)($_POST['requirement_key'] ?? ''),
                'audit_id' => $auditId,
            ), $uid);
            cmp_flash_set('success', 'Finding created.');
            redirect('/admin/compliance/findings.php?id=' . $id);
        }

        if ($action === 'update_finding') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Invalid finding.');
            }
            $auditRaw = trim((string)($_POST['audit_id'] ?? ''));
            $auditId = $auditRaw !== '' ? (int)$auditRaw : null;
            if ($auditId !== null && $auditId <= 0) {
                $auditId = null;
            }

            ComplianceFindingEngine::update($pdo, $fid, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'classification' => (string)($_POST['classification'] ?? ''),
                'severity' => (string)($_POST['severity'] ?? ''),
                'status' => (string)($_POST['status'] ?? ''),
                'reference' => (string)($_POST['reference'] ?? ''),
                'raised_date' => (string)($_POST['raised_date'] ?? ''),
                'target_date' => (string)($_POST['target_date'] ?? ''),
                'regulation_summary' => (string)($_POST['regulation_summary'] ?? ''),
                'notes' => (string)($_POST['notes'] ?? ''),
                'domain_code' => (string)($_POST['domain_code'] ?? ''),
                'requirement_key' => (string)($_POST['requirement_key'] ?? ''),
                'audit_id' => $auditId,
            ), $uid);
            cmp_flash_set('success', 'Finding saved.');
            redirect('/admin/compliance/findings.php?id=' . $fid);
        }

        if ($action === 'save_rca') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Invalid finding.');
            }
            $row = ComplianceFindingEngine::getById($pdo, $fid);
            if ($row === null) {
                throw new RuntimeException('Finding not found.');
            }
            $steps = cmp_rca_steps_from_post();
            $root = trim((string)($_POST['root_cause'] ?? ''));
            $rootCause = $root !== '' ? $root : null;

            $existingRca = ComplianceRcaCapEngine::getRcaForFinding($pdo, $fid);
            $aiAssist = $existingRca !== null && !empty($existingRca['ai_assisted']);

            ComplianceRcaCapEngine::saveRcaDraft(
                $pdo,
                $fid,
                $row,
                $steps,
                $rootCause,
                $uid,
                $aiAssist,
                isset($existingRca['ai_run_id']) ? (int)$existingRca['ai_run_id'] : null,
                false
            );
            cmp_flash_set('success', 'RCA draft saved.');
            redirect('/admin/compliance/findings.php?id=' . $fid);
        }

        if ($action === 'lock_rca') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Invalid finding.');
            }
            $row = ComplianceFindingEngine::getById($pdo, $fid);
            if ($row === null) {
                throw new RuntimeException('Finding not found.');
            }
            $approverName = trim((string)($_POST['approver_name'] ?? (string)($user['name'] ?? '')));
            $lockReason = trim((string)($_POST['lock_reason'] ?? ''));
            ComplianceRcaCapEngine::lockRca(
                $pdo,
                $fid,
                $row,
                $uid,
                $approverName,
                $lockReason !== '' ? $lockReason : null
            );
            cmp_flash_set('success', 'RCA locked (immutable).');
            redirect('/admin/compliance/findings.php?id=' . $fid);
        }

        if ($action === 'ai_rca_next') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Invalid finding.');
            }
            $row = ComplianceFindingEngine::getById($pdo, $fid);
            if ($row === null) {
                throw new RuntimeException('Finding not found.');
            }
            $step = ComplianceRcaCapEngine::suggestNextWhyStep($pdo, $fid, $row, $uid);
            cmp_flash_set(
                'success',
                'AI added Why ' . (int)($step['whyNumber'] ?? 0) . ' — review and save if needed.'
            );
            redirect('/admin/compliance/findings.php?id=' . $fid);
        }

        if ($action === 'attach_regulation_link') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Invalid finding.');
            }
            $row = ComplianceFindingEngine::getById($pdo, $fid);
            if ($row === null) {
                throw new RuntimeException('Finding not found.');
            }
            if (!empty($row['locked_at'])) {
                throw new RuntimeException('Finding is locked.');
            }
            $kind = trim((string)($_POST['source_kind'] ?? ''));
            $sourceId = trim((string)($_POST['source_id'] ?? ''));
            $citationUrl = trim((string)($_POST['citation_url'] ?? ''));
            $citationUrl = $citationUrl !== '' ? $citationUrl : null;
            $extLabel = trim((string)($_POST['citation_label'] ?? ''));
            if ($kind === ComplianceRegulatoryLinkEngine::KIND_EXTERNAL) {
                $rawUrl = trim((string)($_POST['external_url'] ?? ''));
                if ($rawUrl === '' && $sourceId !== '') {
                    $rawUrl = $sourceId;
                }
                $norm = ComplianceRegulatoryLinkEngine::normaliseExternalSourceId($rawUrl);
                $sourceId = $norm[0];
                $citationUrl = $norm[1];
            }
            ComplianceRegulatoryLinkEngine::attach(
                $pdo,
                $fid,
                $kind,
                $sourceId,
                $extLabel !== '' ? $extLabel : null,
                $citationUrl,
                (string)($_POST['link_type'] ?? 'PRIMARY'),
                (string)($_POST['confidence'] ?? 'MANUAL'),
                trim((string)($_POST['notes'] ?? '')) !== '' ? trim((string)($_POST['notes'] ?? '')) : null,
                $uid
            );
            cmp_flash_set('success', 'Regulatory citation attached.');
            redirect('/admin/compliance/findings.php?id=' . $fid);
        }

        if ($action === 'detach_regulation_link') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($fid <= 0 || $linkId <= 0) {
                throw new RuntimeException('Invalid link or finding.');
            }
            $row = ComplianceFindingEngine::getById($pdo, $fid);
            if ($row === null) {
                throw new RuntimeException('Finding not found.');
            }
            if (!empty($row['locked_at'])) {
                throw new RuntimeException('Finding is locked.');
            }
            ComplianceRegulatoryLinkEngine::detach($pdo, $linkId, $fid);
            cmp_flash_set('success', 'Citation removed.');
            redirect('/admin/compliance/findings.php?id=' . $fid);
        }
    } catch (Throwable $e) {
        cmp_flash_set('error', $e->getMessage());
        $backId = (int)($_POST['finding_id'] ?? 0);
        if ($backId > 0) {
            redirect('/admin/compliance/findings.php?id=' . $backId);
        }
        redirect('/admin/compliance/findings.php');
    }
}

$flash = cmp_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

cw_header('Compliance · Findings');

/* Page wrapper opens below — for the list view we open it AFTER we know
   the row count so we can pass it as a stat chip. For the detail view
   the wrapper is opened with audit context. */

$optionsClass = array(
    'LEVEL_1' => 'Level 1',
    'LEVEL_2' => 'Level 2',
    'LEVEL_3' => 'Level 3',
    'OBSERVATION' => 'Observation',
    'INFORMATION' => 'Information',
);
$optionsSev = array(
    'LOW' => 'Low',
    'MEDIUM' => 'Medium',
    'HIGH' => 'High',
    'CRITICAL' => 'Critical',
);
$optionsStatus = array(
    'OPEN' => 'Open',
    'AWAITING_RCA_CAP' => 'Awaiting RCA/CAP',
    'AWAITING_REVIEW' => 'Awaiting review',
    'REVISION_REQUIRED' => 'Revision required',
    'APPROVED_ACTIONS_PENDING' => 'Approved actions pending',
    'IN_PROGRESS' => 'In progress',
    'ACTIONS_IN_PROGRESS' => 'Actions in progress',
    'AWAITING_EFFECTIVENESS_REVIEW' => 'Awaiting effectiveness review',
    'WAITING_AUTHORITY' => 'Waiting authority',
    'ESCALATED' => 'Escalated',
    'CLOSED' => 'Closed',
    'CANCELLED' => 'Cancelled',
);

try {
    $audits = ComplianceFindingEngine::listAuditsForSelect($pdo);
} catch (Throwable $e) {
    $audits = array();
}

if ($detailId > 0) {
    try {
        $finding = ComplianceFindingEngine::getById($pdo, $detailId);
    } catch (Throwable $e) {
        $finding = null;
        compliance_page_open(array(
            'overline' => 'Compliance',
            'title' => 'Database error',
            'description' => $e->getMessage(),
            'flash' => $flash,
            'back' => array('href' => '/admin/compliance/findings.php', 'label' => 'All findings'),
        ));
        compliance_page_close();
        cw_footer();
        exit;
    }

    if ($finding === null) {
        compliance_page_open(array(
            'overline' => 'Compliance',
            'title' => 'Finding not found',
            'description' => 'The finding you requested could not be located. It may have been deleted or you may not have access.',
            'flash' => $flash,
            'back' => array('href' => '/admin/compliance/findings.php', 'label' => 'All findings'),
        ));
    } else {
        $rca = null;
        try {
            $rca = ComplianceRcaCapEngine::getRcaForFinding($pdo, $detailId);
        } catch (Throwable $e) {
            echo '<p class="queue-status is-warn">' . h($e->getMessage()) . '</p>';
        }

        $steps = array();
        if ($rca !== null && !empty($rca['steps_json'])) {
            $rawJ = $rca['steps_json'];
            if (is_array($rawJ)) {
                $steps = ComplianceRcaCapEngine::normaliseSteps($rawJ);
            } elseif (is_string($rawJ)) {
                $dec = json_decode($rawJ, true);
                if (is_array($dec)) {
                    $steps = ComplianceRcaCapEngine::normaliseSteps($dec);
                }
            }
        }

        $byWhy = array();
        foreach ($steps as $s) {
            $byWhy[(int)$s['whyNumber']] = $s;
        }

        $findingLocked = !empty($finding['locked_at']);
        $rcaLocked = $rca !== null && !empty($rca['locked_at']);

        $regLinks = array();
        try {
            $regLinks = ComplianceRegulatoryLinkEngine::listForFinding($pdo, $detailId);
        } catch (Throwable $e) {
            $regLinks = array();
        }
        $submissions = ComplianceRcaCapSubmissionEngine::listForFinding($pdo, $detailId);
        $closureReadiness = ComplianceFindingEngine::closureReadiness($pdo, $detailId);

        $sev = (string)($finding['severity'] ?? '');
        $stRaw = (string)($finding['status'] ?? '');
        compliance_page_open(array(
            'overline' => 'Compliance · Finding',
            'title' => (string)$finding['finding_code'],
            'description' => (string)$finding['title'],
            'stats' => array(
                array('label' => 'Level', 'value' => compliance_friendly_label((string)$finding['classification']), 'tone' => (string)$finding['classification'] === 'LEVEL_1' ? 'crit' : ((string)$finding['classification'] === 'LEVEL_2' ? 'warn' : 'ok')),
                array('label' => 'Severity', 'value' => compliance_friendly_label($sev), 'tone' => $sev === 'CRITICAL' || $sev === 'HIGH' ? 'crit' : ($sev === 'MEDIUM' ? 'warn' : 'ok')),
                array('label' => 'Status', 'value' => compliance_friendly_label($stRaw), 'tone' => in_array($stRaw, array('CLOSED','CANCELLED'), true) ? 'ok' : 'warn'),
            ),
            'back' => array(
                'href' => '/admin/compliance/findings.php',
                'label' => 'All findings',
                'code' => (string)$finding['finding_code'],
            ),
            'flash' => $flash,
        ));
        ?>

        <section class="cmp-card">
          <div class="cmp-meta-line" style="margin-bottom:14px;">
            <?= compliance_badge((string)$finding['classification'], 'level') ?>
            <?= compliance_badge($sev, 'severity') ?>
            <?= compliance_badge($stRaw) ?>
          </div>
          <h2 style="margin:0 0 8px;">Finding details</h2>
          <?php if ($findingLocked): ?>
            <p class="queue-status is-warn" style="display:inline-block;">This finding is locked.</p>
          <?php endif; ?>

          <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$detailId ?>" style="margin-top:16px;">
            <input type="hidden" name="action" value="update_finding">
            <input type="hidden" name="finding_id" value="<?= (int)$detailId ?>">

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:4px;">Audit (optional)</span>
              <select name="audit_id" style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                <option value="">— None —</option>
                <?php foreach ($audits as $a): ?>
                  <option value="<?= (int)$a['id'] ?>"
                    <?= ((int)($finding['audit_id'] ?? 0) === (int)$a['id']) ? 'selected' : '' ?>>
                    <?= h((string)$a['audit_code'] . ' — ' . (string)$a['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="cmp-field compliance-field--full" style="margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Title *</span>
              <input name="title" required value="<?= h((string)$finding['title']) ?>"
                <?= $findingLocked ? 'disabled' : '' ?>>
            </label>

            <label class="cmp-field compliance-field--full" style="margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Description *</span>
              <textarea name="description" required rows="6"
                <?= $findingLocked ? 'disabled' : '' ?>><?= h((string)$finding['description']) ?></textarea>
            </label>

            <div style="display:flex;flex-wrap:wrap;gap:16px;">
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Classification</span>
                <select name="classification" style="padding:8px;border-radius:8px;" <?= $findingLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsClass as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$finding['classification'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Severity</span>
                <select name="severity" style="padding:8px;border-radius:8px;" <?= $findingLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsSev as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$finding['severity'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Status</span>
                <select name="status" style="padding:8px;border-radius:8px;" <?= $findingLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsStatus as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$finding['status'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <label style="display:block;margin:12px 0;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Authority reference</span>
              <input name="reference" value="<?= h((string)($finding['reference'] ?? '')) ?>"
                style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $findingLocked ? 'disabled' : '' ?>>
            </label>

            <div style="display:flex;flex-wrap:wrap;gap:16px;">
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Raised date</span>
                <input type="date" name="raised_date" value="<?= h(substr((string)$finding['raised_date'], 0, 10)) ?>"
                  style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                  <?= $findingLocked ? 'disabled' : '' ?>>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Target date</span>
                <input type="date" name="target_date"
                  value="<?= !empty($finding['target_date']) ? h(substr((string)$finding['target_date'], 0, 10)) : '' ?>"
                  style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                  <?= $findingLocked ? 'disabled' : '' ?>>
              </label>
            </div>

            <label class="cmp-field compliance-field--full" style="margin:12px 0;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Regulation summary (cache / free text)</span>
              <textarea name="regulation_summary" rows="3"
                <?= $findingLocked ? 'disabled' : '' ?>><?= h((string)($finding['regulation_summary'] ?? '')) ?></textarea>
            </label>

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Domain code</span>
              <input name="domain_code" value="<?= h((string)($finding['domain_code'] ?? '')) ?>"
                style="width:100%;max-width:320px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $findingLocked ? 'disabled' : '' ?>>
            </label>

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">MCCF requirement key</span>
              <input name="requirement_key" value="<?= h((string)($finding['requirement_key'] ?? '')) ?>"
                style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $findingLocked ? 'disabled' : '' ?>>
            </label>

            <label class="cmp-field compliance-field--full" style="margin-bottom:16px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Notes</span>
              <textarea name="notes" rows="3"
                <?= $findingLocked ? 'disabled' : '' ?>><?= h((string)($finding['notes'] ?? '')) ?></textarea>
            </label>

            <?php if (!$findingLocked): ?>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">
                Save finding
              </button>
            <?php endif; ?>
          </form>
        </section>

        <section class="cmp-card">
          <h2 style="margin:0 0 8px;font-size:20px;">Regulatory References</h2>
          <p style="color:#64748b;font-size:14px;margin:0 0 14px;line-height:1.5;">
            Structured references are linked through the existing Resource Library-backed regulatory bridge. Use the regulation search to attach indexed EASA / AIM resources or verified external authority URLs.
          </p>
          <p style="margin:0 0 16px;">
            <a href="/admin/compliance/regulations.php?finding_id=<?= (int)$detailId ?>"
              style="display:inline-block;background:#3730a3;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:700;">
              Search regulatory references
            </a>
          </p>
          <?php if ($regLinks === array()): ?>
            <p style="color:#64748b;font-size:14px;margin:0;">No citations attached yet.</p>
          <?php else: ?>
            <div class="compliance-table-wrap">
            <table class="compliance-table" style="width:100%;border-collapse:collapse;font-size:13px;">
              <thead>
                <tr style="background:#f8fafc;text-align:left;">
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Kind</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Label</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Ref</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Type</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($regLinks as $L):
                    $k = (string)($L['source_kind'] ?? '');
                    $kindLab = $k === ComplianceRegulatoryLinkEngine::KIND_AIM ? 'AIM'
                        : ($k === ComplianceRegulatoryLinkEngine::KIND_EASA ? 'EASA' : ($k === ComplianceRegulatoryLinkEngine::KIND_EXTERNAL ? 'URL' : $k));
                    $label = trim((string)($L['citation_label'] ?? ''));
                    $curl = trim((string)($L['citation_url'] ?? ''));
                    ?>
                  <tr>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;"><?= h($kindLab) ?></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;"><?= $label !== '' ? h($label) : '—' ?></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:11px;word-break:break-all;">
                      <?php if ($curl !== ''): ?>
                        <a href="<?= h($curl) ?>" target="_blank" rel="noopener" style="color:#1e3c72;"><?= h((string)($L['source_id'] ?? '')) ?></a>
                      <?php else: ?>
                        <?= h((string)($L['source_id'] ?? '')) ?>
                      <?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;"><?= h((string)($L['link_type'] ?? '')) ?></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;">
                      <?php if (!$findingLocked): ?>
                        <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$detailId ?>" style="display:inline;"
                          onsubmit="return confirm('Remove this citation?');">
                          <input type="hidden" name="action" value="detach_regulation_link">
                          <input type="hidden" name="finding_id" value="<?= (int)$detailId ?>">
                          <input type="hidden" name="link_id" value="<?= (int)($L['id'] ?? 0) ?>">
                          <button type="submit" style="background:#fee2e2;color:#991b1b;border:0;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                            Remove
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="cmp-card">
          <h2 style="margin:0 0 8px;font-size:20px;">Root cause analysis (5 Whys)</h2>
          <p style="color:#64748b;font-size:14px;margin:0 0 16px;line-height:1.5;">
            AI suggests the <em>next</em> Why step (legacy parity). Each suggestion is logged in
            <code>ipca_compliance_ai_runs</code>. Locking the RCA requires a human approver name — AI cannot lock.
          </p>
          <?php if ($findingLocked): ?>
            <p class="cmp-flash is-warn">RCA editing is locked because this finding is locked. Reopen or unlock the finding through the existing finding workflow before changing RCA content.</p>
          <?php elseif ($rcaLocked): ?>
            <p class="cmp-flash is-ok">RCA is locked because it was approved by a human approver. The existing workflow does not expose an unlock action here.</p>
          <?php else: ?>
            <p class="cmp-flash is-ok">RCA is editable. Save the draft first, then use the visible Lock RCA action when the analysis is ready for approval.</p>
          <?php endif; ?>
          <?php if ($rcaLocked): ?>
            <p class="queue-status is-ok" style="display:inline-block;margin-bottom:12px;">
              RCA locked<?php
                $an = (string)($rca['approved_by_name'] ?? '');
                echo $an !== '' ? ' — approved by ' . h($an) : '';
              ?>.
            </p>
          <?php endif; ?>

          <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$detailId ?>" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="save_rca">
            <input type="hidden" name="finding_id" value="<?= (int)$detailId ?>">

            <?php for ($i = 1; $i <= 5; $i++):
                $rowS = $byWhy[$i] ?? null;
                $q = $rowS ? (string)$rowS['question'] : '';
                $a = $rowS ? (string)$rowS['answer'] : '';
                ?>
              <div style="margin-bottom:18px;padding:14px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                <div style="font-weight:800;color:#1e3c72;margin-bottom:8px;">Why <?= $i ?></div>
                <label style="display:block;margin-bottom:8px;">
                  <span style="font-size:12px;color:#64748b;">Question</span>
                  <input name="why_<?= $i ?>_q" value="<?= h($q) ?>"
                    style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                    <?= ($findingLocked || $rcaLocked) ? 'disabled' : '' ?>>
                </label>
                <label style="display:block;">
                  <span style="font-size:12px;color:#64748b;">Answer</span>
                  <textarea name="why_<?= $i ?>_a" rows="2"
                    style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                    <?= ($findingLocked || $rcaLocked) ? 'disabled' : '' ?>><?= h($a) ?></textarea>
                </label>
              </div>
            <?php endfor; ?>

            <label style="display:block;margin-bottom:16px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Root cause statement</span>
              <textarea name="root_cause" rows="3"
                style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= ($findingLocked || $rcaLocked) ? 'disabled' : '' ?>><?= h((string)($rca['root_cause_text'] ?? '')) ?></textarea>
            </label>

            <?php if (!$findingLocked && !$rcaLocked): ?>
              <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;margin-right:8px;">
                Save RCA draft
              </button>
            <?php endif; ?>
          </form>

          <?php if (!$findingLocked && !$rcaLocked): ?>
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$detailId ?>" onsubmit="return confirm('Request next Why from AI?');">
                <input type="hidden" name="action" value="ai_rca_next">
                <input type="hidden" name="finding_id" value="<?= (int)$detailId ?>">
                <button type="submit" style="background:#3730a3;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">
                  AI: suggest next Why
                </button>
              </form>

              <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$detailId ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                <input type="hidden" name="action" value="lock_rca">
                <input type="hidden" name="finding_id" value="<?= (int)$detailId ?>">
                <label style="margin:0;">
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Approver name *</span>
                  <input name="approver_name" required value="<?= h((string)($user['name'] ?? '')) ?>"
                    style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;min-width:200px;">
                </label>
                <label style="margin:0;">
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Lock reason</span>
                  <input name="lock_reason" placeholder="Optional"
                    style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;min-width:200px;">
                </label>
                <button type="submit" style="background:#b45309;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;"
                  onclick="return confirm('Lock RCA? It will become immutable.');">
                  Lock RCA
                </button>
              </form>
            </div>
            <?php endif; ?>
        </section>

        <?php
        $capItems = array();
        try {
            $capItems = ComplianceCapEngine::listForFinding($pdo, $detailId);
        } catch (Throwable $e) {
            $capItems = array();
        }
        ?>
        <section class="cmp-card">
          <h2 style="margin:0 0 8px;font-size:20px;">Corrective actions (CAP)</h2>
          <p style="color:#64748b;font-size:14px;margin:0 0 12px;line-height:1.5;">
            Manage CAP items linked to this finding or export a combined PDF (finding + RCA + actions).
          </p>
          <p style="margin:0 0 14px;">
            <a href="/admin/compliance/corrective_actions.php?finding_id=<?= (int)$detailId ?>"
              style="display:inline-block;background:#1e3c72;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:700;">
              Open CAP workspace
            </a>
            <a href="/admin/compliance/export_rca_cap_pdf.php?finding_id=<?= (int)$detailId ?>"
              style="display:inline-block;margin-left:10px;background:#f1f5f9;color:#1e3c72;text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:700;border:1px solid #cbd5e1;">
              Export PDF
            </a>
          </p>
          <?php if ($capItems === array()): ?>
            <p style="color:#64748b;font-size:14px;margin:0;">No corrective actions yet for this finding.</p>
          <?php else: ?>
            <div class="compliance-table-wrap">
            <table class="compliance-table" style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
              <thead>
                <tr style="background:#f8fafc;text-align:left;">
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;width:150px;">Code</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Title</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;width:150px;">Status</th>
                  <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;width:180px;">Due</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($capItems as $c): ?>
                  <tr data-href="/admin/compliance/corrective_actions.php?id=<?= (int)$c['id'] ?>" class="compliance-row-clickable">
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px;">
                      <a href="/admin/compliance/corrective_actions.php?id=<?= (int)$c['id'] ?>" style="color:#1e3c72;font-weight:700;">
                        <?= h((string)$c['action_code']) ?>
                      </a>
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;"><?= h((string)$c['title']) ?></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;"><?= compliance_badge((string)$c['status']) ?></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;">
                      <?= compliance_deadline_badge(isset($c['due_date']) ? (string)$c['due_date'] : null) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </section>
        <section class="cmp-card">
          <h2 style="margin:0 0 8px;font-size:20px;">RCA/CAP Timeline</h2>
          <p style="color:#64748b;font-size:14px;margin:0 0 14px;line-height:1.5;">
            Submission attempts are historical governance records. Approved, rejected, submitted and superseded attempts are not overwritten.
          </p>
          <?php if ($submissions === array()): ?>
            <p style="color:#64748b;font-size:14px;margin:0;">No RCA/CAP submission attempts have been recorded yet.</p>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px;">
              <?php foreach ($submissions as $sub): ?>
                <?php $sid = (int)$sub['id']; ?>
                <div style="border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#f8fafc;">
                  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                    <strong style="color:#0f172a;">Submission #<?= (int)$sub['submission_no'] ?></strong>
                    <?= compliance_badge((string)$sub['status']) ?>
                    <span class="cmp-mono"><?= h((string)$sub['submission_type']) ?></span>
                    <?php if (!empty($sub['email_thread_id'])): ?>
                      <a href="/admin/compliance/email_thread.php?id=<?= (int)$sub['email_thread_id'] ?>" style="color:#1e3c72;font-weight:700;text-decoration:none;">Linked communication</a>
                    <?php endif; ?>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;color:#64748b;font-size:13px;">
                    <div><strong>Submitted:</strong> <?= !empty($sub['submitted_at']) ? h((string)$sub['submitted_at']) : 'Draft / not submitted' ?></div>
                    <div><strong>Reviewed:</strong> <?= !empty($sub['reviewed_at']) ? h((string)$sub['reviewed_at']) : 'Pending' ?></div>
                    <div><strong>Proposed RCA deadline:</strong> <?= !empty($sub['proposed_rca_deadline']) ? h(substr((string)$sub['proposed_rca_deadline'], 0, 10)) : '—' ?></div>
                    <div><strong>Approved RCA deadline:</strong> <?= !empty($sub['approved_rca_deadline']) ? h(substr((string)$sub['approved_rca_deadline'], 0, 10)) : '—' ?></div>
                    <div><strong>Proposed CAP deadline:</strong> <?= !empty($sub['proposed_cap_deadline']) ? h(substr((string)$sub['proposed_cap_deadline'], 0, 10)) : '—' ?></div>
                    <div><strong>Approved CAP deadline:</strong> <?= !empty($sub['approved_cap_deadline']) ? h(substr((string)$sub['approved_cap_deadline'], 0, 10)) : '—' ?></div>
                  </div>
                  <?php if (trim((string)($sub['review_notes'] ?? '')) !== ''): ?>
                    <p style="margin:10px 0 0;color:#475569;font-size:13px;"><?= h((string)$sub['review_notes']) ?></p>
                  <?php endif; ?>
                  <?php $exts = ComplianceDeadlineExtensionEngine::listForSubmission($pdo, $sid); ?>
                  <?php if ($exts !== array()): ?>
                    <div style="margin-top:10px;color:#64748b;font-size:13px;">
                      <strong>Deadline extensions:</strong>
                      <?php foreach ($exts as $ext): ?>
                        <span class="cmp-pill" style="margin-left:6px;"><?= h((string)$ext['deadline_type']) ?> #<?= (int)$ext['extension_no'] ?> · <?= h((string)$ext['status']) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!$closureReadiness['ready']): ?>
            <div class="cmp-flash is-warn" style="margin-top:14px;">
              <strong>Closure blocked:</strong> <?= h(implode(' ', $closureReadiness['reasons'])) ?>
            </div>
          <?php else: ?>
            <div class="cmp-flash is-ok" style="margin-top:14px;">Closure checks passed: approved actions are executed on time and effectiveness is positive.</div>
          <?php endif; ?>
        </section>
        <?php
        compliance_render_comms_panel($pdo, 'finding', (string)$detailId);
    }
} else {
    try {
        $rows = ComplianceFindingEngine::listRecent($pdo, 150);
    } catch (Throwable $e) {
        $rows = array();
    }
    $filterScope = strtolower((string)($_GET['scope'] ?? 'open'));
    if (!in_array($filterScope, array('open', 'closed', 'all'), true)) {
        $filterScope = 'open';
    }
    $filterQ = trim((string)($_GET['q'] ?? ''));
    $filterSeverity = strtoupper(trim((string)($_GET['severity'] ?? '')));
    $filterLevel = strtoupper(trim((string)($_GET['level'] ?? '')));
    $filterAudit = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : 0;
    $filterFrom = trim((string)($_GET['from'] ?? ''));
    $filterTo = trim((string)($_GET['to'] ?? ''));
    $sort = (string)($_GET['sort'] ?? 'updated_desc');
    $auditCodeById = array();
    foreach ($audits as $auditRow) {
        $auditCodeById[(int)$auditRow['id']] = (string)$auditRow['audit_code'];
    }

    $fCounts = array('open' => 0, 'closed' => 0);
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        if (in_array($st, array('CLOSED', 'VOID', 'CANCELLED'), true)) {
            $fCounts['closed']++;
        } else {
            $fCounts['open']++;
        }
    }

    $authorNames = array();
    $authorIds = array_values(array_unique(array_filter(array_map(static function (array $r): int {
        return (int)($r['created_by'] ?? 0);
    }, $rows))));
    if ($authorIds !== array()) {
        try {
            $in = implode(',', array_fill(0, count($authorIds), '?'));
            $stUsers = $pdo->prepare('SELECT id, name, email FROM users WHERE id IN (' . $in . ')');
            $stUsers->execute($authorIds);
            foreach (($stUsers->fetchAll(PDO::FETCH_ASSOC) ?: array()) as $u) {
                $name = trim((string)($u['name'] ?? ''));
                $authorNames[(int)$u['id']] = $name !== '' ? $name : (string)($u['email'] ?? '');
            }
        } catch (Throwable $e) {
            $authorNames = array();
        }
    }

    $rows = array_values(array_filter($rows, static function (array $r) use ($filterScope, $filterQ, $filterSeverity, $filterLevel, $filterAudit, $filterFrom, $filterTo): bool {
        $status = strtoupper((string)($r['status'] ?? ''));
        $isClosed = in_array($status, array('CLOSED', 'VOID', 'CANCELLED'), true);
        if ($filterScope === 'open' && $isClosed) { return false; }
        if ($filterScope === 'closed' && !$isClosed) { return false; }
        if ($filterSeverity !== '' && strtoupper((string)($r['severity'] ?? '')) !== $filterSeverity) { return false; }
        if ($filterLevel !== '' && strtoupper((string)($r['classification'] ?? '')) !== $filterLevel) { return false; }
        if ($filterAudit > 0 && (int)($r['audit_id'] ?? 0) !== $filterAudit) { return false; }
        if ($filterQ !== '') {
            $hay = strtolower((string)($r['finding_code'] ?? '') . ' ' . (string)($r['title'] ?? '') . ' ' . (string)($r['description'] ?? '') . ' ' . (string)($r['reference'] ?? '') . ' ' . (string)($r['regulation_summary'] ?? ''));
            if (strpos($hay, strtolower($filterQ)) === false) { return false; }
        }
        $raised = substr((string)($r['raised_date'] ?? ''), 0, 10);
        if ($filterFrom !== '' && $raised !== '' && $raised < $filterFrom) { return false; }
        if ($filterTo !== '' && $raised !== '' && $raised > $filterTo) { return false; }
        return true;
    }));

    usort($rows, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'raised_desc') { return strcmp((string)($b['raised_date'] ?? ''), (string)($a['raised_date'] ?? '')); }
        if ($sort === 'raised_asc') { return strcmp((string)($a['raised_date'] ?? ''), (string)($b['raised_date'] ?? '')); }
        if ($sort === 'severity_desc') { return strcmp((string)($a['severity'] ?? ''), (string)($b['severity'] ?? '')); }
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });

    compliance_page_open(array(
        'overline' => 'Compliance · Findings',
        'title' => 'Findings',
        'description' => 'Create and manage non-conformance reports (NCRs). Open a row for full record incl. 5-Whys RCA, regulatory citations and corrective actions.',
        'actions' => array(
            array('label' => 'New finding', 'modal' => 'finding-create-modal', 'icon' => 'plus'),
        ),
        'stats' => array(
            array('label' => 'Open',   'value' => (int)$fCounts['open'],   'tone' => $fCounts['open'] > 0 ? 'warn' : 'ok'),
            array('label' => 'Closed', 'value' => (int)$fCounts['closed'], 'tone' => 'ok'),
            array('label' => 'Total',  'value' => count($rows)),
        ),
        'flash' => $flash,
    ));
    ?>

    <section class="cmp-card">
      <form method="get" class="compliance-filterbar compliance-filterbar--findings">
        <label class="cmp-field compliance-filterbar__search">
          <span>Search</span>
          <input type="search" name="q" value="<?= h($filterQ) ?>" placeholder="NCR code, title, description or reference">
        </label>
        <label class="cmp-field">
          <span>Scope</span>
          <select name="scope">
            <option value="open" <?= $filterScope === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="closed" <?= $filterScope === 'closed' ? 'selected' : '' ?>>Closed</option>
            <option value="all" <?= $filterScope === 'all' ? 'selected' : '' ?>>All</option>
          </select>
        </label>
        <label class="cmp-field">
          <span>Severity</span>
          <select name="severity">
            <option value="">All</option>
            <?php foreach ($optionsSev as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= $filterSeverity === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="cmp-field">
          <span>Level</span>
          <select name="level">
            <option value="">All</option>
            <?php foreach ($optionsClass as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= $filterLevel === $k ? 'selected' : '' ?>><?= h(compliance_friendly_label($k)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="cmp-field">
          <span>Audit</span>
          <select name="audit_id">
            <option value="0">All audits</option>
            <?php foreach ($audits as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $filterAudit === (int)$a['id'] ? 'selected' : '' ?>><?= h((string)$a['audit_code']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="cmp-field compliance-filterbar__row2">
          <span>From</span>
          <input type="date" name="from" value="<?= h($filterFrom) ?>">
        </label>
        <label class="cmp-field compliance-filterbar__row2">
          <span>To</span>
          <input type="date" name="to" value="<?= h($filterTo) ?>">
        </label>
        <label class="cmp-field compliance-filterbar__row2">
          <span>Sort</span>
          <select name="sort">
            <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>Recently updated</option>
            <option value="raised_desc" <?= $sort === 'raised_desc' ? 'selected' : '' ?>>Raised newest</option>
            <option value="raised_asc" <?= $sort === 'raised_asc' ? 'selected' : '' ?>>Raised oldest</option>
            <option value="severity_desc" <?= $sort === 'severity_desc' ? 'selected' : '' ?>>Severity</option>
          </select>
        </label>
        <div class="cmp-toolbar-actions compliance-filterbar__actions compliance-filterbar__row2">
          <button type="submit">Apply filters</button>
          <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/findings.php">Clear</a>
        </div>
      </form>
    </section>

      <section class="cmp-card compliance-card--full" style="overflow:hidden;">
        <div class="cmp-list-head" style="margin-bottom:14px;">
          <div class="cmp-list-title"><?= compliance_ui_icon('flag') ?><span>Findings</span></div>
          <div class="cmp-count-pill"><?= count($rows) ?></div>
        </div>
        <div class="compliance-table-wrap">
        <style>
          .cmp-page .cmp-finding-list-table th,
          .cmp-page .cmp-finding-list-table td,
          .cmp-page .cmp-finding-list-table td:first-child,
          .cmp-page .cmp-finding-list-table .cmp-mono,
          .cmp-page .cmp-finding-list-table td:first-child a,
          .cmp-page .cmp-finding-list-table .cmp-ref-link{
            font-family:var(--font-sans,Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif) !important;
            font-size:11.5px !important;
            color:#324155 !important;
            font-weight:650;
            letter-spacing:.01em;
          }
          .cmp-finding-list-table .cmp-ref-link{color:#324155 !important;font-weight:720;text-decoration:none;}
          .cmp-finding-list-table .cmp-list-titlecell{
            max-width:640px;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            line-height:1.35;
          }
          .cmp-finding-list-table .cmp-list-deadline{display:flex;flex-direction:column;gap:4px;align-items:flex-start;}
          .cmp-finding-list-table .cmp-list-date{font-size:11.5px;color:#324155;font-weight:650;}
        </style>
        <table class="compliance-table cmp-finding-list-table">
          <thead>
            <tr>
              <th style="width:143px;">Reference</th>
              <th style="width:143px;">Audit ref</th>
              <th>Title</th>
              <th style="width:116px;">Class</th>
              <th style="width:105px;">Severity</th>
              <th style="width:127px;">Status</th>
              <th style="width:165px;">Target date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" style="padding:20px;color:var(--text-muted);">No findings match this filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
              $sev = (string)$r['severity'];
              $stRaw = (string)$r['status'];
                $auditIdForRow = (int)($r['audit_id'] ?? 0);
                $targetDate = cmp_finding_target_date($pdo, (int)$r['id'], isset($r['target_date']) ? (string)$r['target_date'] : null);
                $authorityRef = trim((string)($r['reference'] ?? ''));
                $displayRef = $authorityRef !== '' ? $authorityRef : (string)$r['finding_code'];
            ?>
              <tr data-href="/admin/compliance/findings.php?id=<?= (int)$r['id'] ?>" class="compliance-row-clickable">
                <td>
                  <a class="cmp-ref-link" href="/admin/compliance/findings.php?id=<?= (int)$r['id'] ?>">
                    <?= h($displayRef) ?>
                  </a>
                </td>
                <td><?= $auditIdForRow > 0 && isset($auditCodeById[$auditIdForRow]) ? h($auditCodeById[$auditIdForRow]) : '<span style="color:var(--text-muted);">—</span>' ?></td>
                <td><span class="cmp-list-titlecell"><?= h((string)$r['title']) ?></span></td>
                <td><?= compliance_badge((string)$r['classification'], 'level') ?></td>
                <td><?= compliance_badge($sev, 'severity') ?></td>
                <td><?= compliance_badge($stRaw) ?></td>
                <td><?= cmp_finding_target_date_display($targetDate) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>

      <?php compliance_modal_open('finding-create-modal', 'New finding'); ?>
        <form method="post" action="/admin/compliance/findings.php">
          <input type="hidden" name="action" value="create_finding">

          <label class="cmp-field">
            <span class="cmp-field-label">Audit (optional)</span>
            <select name="audit_id">
              <option value="">— None —</option>
              <?php foreach ($audits as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= h((string)$a['audit_code'] . ' — ' . (string)$a['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="cmp-field">
            <span class="cmp-field-label">Title *</span>
            <input name="title" required>
          </label>

          <label class="cmp-field">
            <span class="cmp-field-label">Description *</span>
            <textarea name="description" required rows="5"></textarea>
          </label>

          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
            <label class="cmp-field">
              <span class="cmp-field-label">Classification</span>
              <select name="classification">
                <?php foreach ($optionsClass as $k => $lab): ?>
                  <option value="<?= h($k) ?>" <?= $k === 'LEVEL_3' ? 'selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="cmp-field">
              <span class="cmp-field-label">Severity</span>
              <select name="severity">
                <?php foreach ($optionsSev as $k => $lab): ?>
                  <option value="<?= h($k) ?>" <?= $k === 'MEDIUM' ? 'selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <label class="cmp-field">
            <span class="cmp-field-label">Raised date</span>
            <input type="date" name="raised_date" value="<?= h(date('Y-m-d')) ?>">
          </label>

          <div class="compliance-modal__footer">
            <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
            <button type="submit">Create finding</button>
          </div>
        </form>
      <?php compliance_modal_close(); ?>
    <?php
}

compliance_page_close();
cw_footer();
