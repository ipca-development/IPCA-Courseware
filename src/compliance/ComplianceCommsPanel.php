<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceCommsCenterEngine.php';

/**
 * Compliance object pages — embeddable "Communications" panel.
 *
 * Every compliance object detail view (finding, case/MoC, audit, corrective
 * action, manual change request, meeting, regulatory change, authority
 * report) can drop this panel inline to surface the inbound / outbound
 * email correspondence linked to that object.
 *
 * Usage:
 *   require_once .../ComplianceCommsPanel.php;
 *   compliance_render_comms_panel($pdo, 'finding', (string)$findingId);
 *
 * The panel:
 *   * Lists every linked email in chronological order with direction badge,
 *     subject (deep-link to the thread view), contact, status, and the
 *     "role" recorded in ipca_compliance_email_obj_links.
 *   * Renders a "Compose new" button that pre-fills nothing — the operator
 *     uses the link-to-object form on the thread view itself once sent.
 *   * Renders an "Attach existing thread" inline form (in case a thread is
 *     already in the system but hasn't been linked yet).
 *
 * Style tokens prefixed `.cmpcp-` so they don't clash with the host page.
 * No new HTTP routes — the attach form POSTs to /admin/compliance/inbox.php
 * style? No — easier: it posts back to the host page itself, expecting the
 * host page to forward the POST to ComplianceCommsCenterEngine::linkObject
 * if it sees action=link_existing_thread. To keep that contract clean
 * without touching every host page, the form points at a dedicated
 * receiver script: /admin/compliance/email_obj_link.php (added in the same
 * commit).
 */

function compliance_render_comms_panel(PDO $pdo, string $objectType, string $objectId): void
{
    $emails = ComplianceCommsCenterEngine::listEmailsForObject($pdo, $objectType, $objectId, 50);
    $linkable = ComplianceCommsCenterEngine::linkableObjectTypes();
    $linkRoles = ComplianceCommsCenterEngine::linkTypes();
    $threadOptions = ComplianceCommsCenterEngine::listThreadsForPicker($pdo, 200);
    $typeLabel = (string)($linkable[$objectType] ?? ucfirst(str_replace('_', ' ', $objectType)));
    $linkFlash = null;
    if (!empty($_SESSION['_ipca_compliance_flash_objlink']) && is_array($_SESSION['_ipca_compliance_flash_objlink'])) {
        $linkFlash = $_SESSION['_ipca_compliance_flash_objlink'];
        unset($_SESSION['_ipca_compliance_flash_objlink']);
    }
    ?>
    <style>
      .cmpcp-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin:20px 0;}
      .cmpcp-h2{margin:0 0 4px;font-size:16px;color:#0f172a;}
      .cmpcp-sub{margin:0 0 14px;color:#64748b;font-size:13px;}
      .cmpcp-table{width:100%;border-collapse:collapse;font-size:13px;}
      .cmpcp-table th{
        text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
        text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
      }
      .cmpcp-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:top;}
      .cmpcp-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
      .cmpcp-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.04em;}
      .cmpcp-pill.dir-inbound{background:#dbeafe;color:#1e3a8a;}
      .cmpcp-pill.dir-outbound{background:#d1fae5;color:#065f46;}
      .cmpcp-pill.role-evidence{background:#fef3c7;color:#92400e;}
      .cmpcp-pill.role-authority_communication{background:#dbeafe;color:#1e3a8a;}
      .cmpcp-pill.role-source{background:#e2e8f0;color:#475569;}
      .cmpcp-pill.role-follow_up{background:#fef3c7;color:#92400e;}
      .cmpcp-pill.role-context{background:#e2e8f0;color:#475569;}
      .cmpcp-empty{padding:12px 16px;color:#64748b;background:#f8fafc;border-radius:10px;font-size:13px;text-align:center;}
      .cmpcp-form{display:grid;grid-template-columns:1fr 220px auto;gap:10px;align-items:end;margin-top:14px;}
      .cmpcp-input,.cmpcp-select{
        width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font-size:13px;
      }
      .cmpcp-label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;}
      .cmpcp-btn{
        display:inline-block;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:700;
        text-decoration:none;border:0;cursor:pointer;line-height:1.2;
      }
      .cmpcp-btn.primary{background:#1e3c72;color:#fff;}
      .cmpcp-btn.secondary{background:#e2e8f0;color:#0f172a;}
      .cmpcp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}
    </style>

    <section class="cmpcp-card">
      <h2 class="cmpcp-h2">Communications</h2>
      <p class="cmpcp-sub">
        Inbound and outbound compliance emails linked to this
        <strong><?= h($typeLabel) ?></strong>
        (<span class="cmpcp-mono"><?= h($objectType) ?>:<?= h($objectId) ?></span>).
      </p>

      <?php if ($linkFlash !== null): ?>
        <div style="margin:0 0 12px;padding:8px 12px;border-radius:8px;font-size:13px;
                    background:<?= $linkFlash['type'] === 'success' ? '#d1fae5' : '#fee2e2' ?>;
                    color:<?= $linkFlash['type'] === 'success' ? '#065f46' : '#991b1b' ?>;
                    border:1px solid <?= $linkFlash['type'] === 'success' ? '#6ee7b7' : '#fca5a5' ?>;">
          <?= h((string)$linkFlash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if ($emails === array()): ?>
        <div class="cmpcp-empty">
          No emails are linked yet. Link an existing thread below, or open the
          <a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;">Inbox</a>
          and use the per-thread "Link to…" form to attach correspondence here.
        </div>
      <?php else: ?>
        <table class="cmpcp-table">
          <thead><tr>
            <th>Dir</th>
            <th>Subject</th>
            <th>Contact / Recipient</th>
            <th>Role</th>
            <th>When</th>
            <th>Open</th>
          </tr></thead>
          <tbody>
            <?php foreach ($emails as $r):
              $eid = (int)$r['id'];
              $tid = (int)$r['thread_id'];
              $direction = (string)$r['direction'];
              $roles = (string)($r['link_roles'] ?? '');
              $rolesList = $roles !== '' ? explode(',', $roles) : array();
              $when = (string)($r['received_at'] ?? $r['sent_at'] ?? '');
            ?>
              <tr>
                <td><span class="cmpcp-pill dir-<?= h($direction) ?>"><?= h(strtoupper($direction)) ?></span></td>
                <td>
                  <a href="/admin/compliance/email_thread.php?email_id=<?= $eid ?>"
                     style="color:#1e3c72;font-weight:700;text-decoration:none;">
                    <?= h((string)($r['subject'] ?? '(no subject)')) ?>
                  </a>
                </td>
                <td class="cmpcp-mono"><?= h((string)($r['from_email'] ?? $r['thread_contact'] ?? '—')) ?></td>
                <td>
                  <?php foreach ($rolesList as $role): $role = trim($role); if ($role === '') continue; ?>
                    <span class="cmpcp-pill role-<?= h($role) ?>" style="margin-right:3px;"><?= h(str_replace('_', ' ', $role)) ?></span>
                  <?php endforeach; ?>
                </td>
                <td class="cmpcp-mono"><?= h(substr($when, 0, 16)) ?></td>
                <td>
                  <?php if ($tid > 0): ?>
                    <a href="/admin/compliance/email_thread.php?id=<?= $tid ?>"
                       style="color:#3730a3;font-weight:700;text-decoration:none;font-size:12px;">
                      thread #<?= $tid ?>
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if ($threadOptions === array()): ?>
        <p style="margin:14px 0 0;color:#64748b;font-size:13px;">
          No email threads on file yet — once correspondence arrives in the
          <a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;">inbox</a>
          you'll be able to attach it here.
        </p>
      <?php else: ?>
        <form method="post" action="/admin/compliance/email_obj_link.php" class="cmpcp-form">
          <input type="hidden" name="linked_object_type" value="<?= h($objectType) ?>">
          <input type="hidden" name="linked_object_id" value="<?= h($objectId) ?>">
          <input type="hidden" name="return_to" value="<?= h((string)($_SERVER['REQUEST_URI'] ?? '')) ?>">
          <div>
            <span class="cmpcp-label">Attach email thread</span>
            <select name="thread_id" class="cmpcp-select" required>
              <option value="" disabled selected>— Choose a thread from the inbox …</option>
              <?php foreach ($threadOptions as $to): ?>
                <option value="<?= (int)$to['id'] ?>"><?= h((string)$to['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <span class="cmpcp-label">Role</span>
            <select name="link_type" class="cmpcp-select">
              <?php foreach ($linkRoles as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= $val === 'authority_communication' ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <span class="cmpcp-label">&nbsp;</span>
            <button type="submit" class="cmpcp-btn primary">Link thread</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="cmpcp-actions">
        <a class="cmpcp-btn secondary" href="/admin/compliance/email_compose.php">
          + Compose new
        </a>
        <a class="cmpcp-btn secondary" href="/admin/compliance/inbox.php">
          Open inbox
        </a>
      </div>
    </section>
    <?php
}
