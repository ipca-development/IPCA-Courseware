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
    <section class="cmp-card cmpcp-card">
      <div class="cmp-card-head">
        <div>
          <h2 class="cmp-card-title">Communications</h2>
          <p class="cmp-card-sub">
            Inbound and outbound compliance emails linked to this
            <strong><?= h($typeLabel) ?></strong>
            <span class="cmp-mono">(<?= h($objectType) ?>:<?= h($objectId) ?>)</span>
          </p>
        </div>
      </div>

      <?php if ($linkFlash !== null): ?>
        <div class="cmp-flash <?= $linkFlash['type'] === 'success' ? 'is-ok' : 'is-danger' ?>">
          <?= h((string)$linkFlash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if ($emails === array()): ?>
        <div class="cmp-empty">
          <div class="cmp-empty-title">No correspondence linked yet</div>
          <p style="margin:0;">
            Link an existing thread below, or open the
            <a href="/admin/compliance/inbox.php">Inbox</a>
            and use the per-thread &ldquo;Link to&hellip;&rdquo; form to attach correspondence here.
          </p>
        </div>
      <?php else: ?>
        <div class="compliance-table-wrap">
        <table class="cmp-table compliance-table">
          <thead><tr>
            <th>Dir</th>
            <th>Subject</th>
            <th>Contact / Recipient</th>
            <th>Role</th>
            <th>When</th>
            <th>Thread</th>
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
                <td><span class="cmp-pill dir-<?= h($direction) ?>"><?= h(strtoupper($direction)) ?></span></td>
                <td>
                  <a href="/admin/compliance/email_thread.php?email_id=<?= $eid ?>">
                    <?= h((string)($r['subject'] ?? '(no subject)')) ?>
                  </a>
                </td>
                <td class="cmp-mono"><?= h((string)($r['from_email'] ?? $r['thread_contact'] ?? '—')) ?></td>
                <td>
                  <?php foreach ($rolesList as $role): $role = trim($role); if ($role === '') continue; ?>
                    <span class="cmp-pill cmpcp-pill role-<?= h($role) ?>" style="margin-right:4px;"><?= h(str_replace('_', ' ', $role)) ?></span>
                  <?php endforeach; ?>
                </td>
                <td class="cmp-mono"><?= h(substr($when, 0, 16)) ?></td>
                <td>
                  <?php if ($tid > 0): ?>
                    <a href="/admin/compliance/email_thread.php?id=<?= $tid ?>">thread #<?= $tid ?></a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>

      <?php if ($threadOptions === array()): ?>
        <p style="margin:18px 0 0;">
          No email threads on file yet &mdash; once correspondence arrives in the
          <a href="/admin/compliance/inbox.php">inbox</a>
          you&rsquo;ll be able to attach it here.
        </p>
      <?php else: ?>
        <form method="post" action="/admin/compliance/email_obj_link.php"
              style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;gap:12px;align-items:end;margin-top:18px;">
          <input type="hidden" name="linked_object_type" value="<?= h($objectType) ?>">
          <input type="hidden" name="linked_object_id" value="<?= h($objectId) ?>">
          <input type="hidden" name="return_to" value="<?= h((string)($_SERVER['REQUEST_URI'] ?? '')) ?>">
          <label>
            <span>Attach email thread</span>
            <select name="thread_id" required>
              <option value="" disabled selected>&mdash; Choose a thread from the inbox &hellip;</option>
              <?php foreach ($threadOptions as $to): ?>
                <option value="<?= (int)$to['id'] ?>"><?= h((string)$to['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Role</span>
            <select name="link_type">
              <?php foreach ($linkRoles as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= $val === 'authority_communication' ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit">Link thread</button>
        </form>
      <?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
        <a class="cmp-btn cmp-btn-secondary" href="/admin/compliance/email_compose.php">+ Compose new</a>
        <a class="cmp-btn cmp-btn-secondary" href="/admin/compliance/inbox.php">Open inbox</a>
      </div>
    </section>
    <?php
}
