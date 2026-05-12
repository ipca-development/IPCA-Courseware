<?php
declare(strict_types=1);
/** @var array<string,mixed> $exportData */

if (!isset($exportData) || !is_array($exportData)) {
    throw new RuntimeException('Invalid export payload');
}

$pkg = $exportData['package'] ?? array();
$approvals = $exportData['approvals'] ?? array();
$drafts = $exportData['drafts'] ?? array();
$generatedAt = (string)($exportData['generated_at'] ?? '');
?>
<style>
body  { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #111827; }
h1    { font-size: 18pt; margin: 0 0 8px; color: #1e3c72; }
h2    { font-size: 12pt; margin: 18px 0 6px; color: #1e3c72; border-bottom: 1px solid #cbd5e1; padding-bottom: 2px; }
.meta { font-size: 9.5pt; color: #6b7280; margin-bottom: 12px; }
.pbox { background: #f8fafc; border: 1px solid #e5e7eb; padding: 10px 12px; border-radius: 4px; margin-bottom: 10px; }
.table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 6px; }
.table th { text-align: left; background: #f1f5f9; padding: 6px; border: 1px solid #e5e7eb; }
.table td { vertical-align: top; padding: 6px; border: 1px solid #e5e7eb; }
.small { font-size: 9pt; color: #6b7280; }
.kv td:first-child { width: 30%; color: #475569; font-weight: 700; }
.draft-body { font-family: "DejaVu Sans Mono", monospace; font-size: 9pt; white-space: pre-wrap; background: #f8fafc; padding: 8px; border: 1px solid #e5e7eb; }
</style>

<h1>Manual release package</h1>
<div class="meta">Generated <?= h($generatedAt) ?></div>

<div class="pbox">
  <table class="table kv">
    <tr><td>Package code</td><td><strong><?= h((string)($pkg['package_code'] ?? '')) ?></strong></td></tr>
    <tr><td>Title</td><td><?= h((string)($pkg['title'] ?? '')) ?></td></tr>
    <tr><td>Status</td><td><?= h((string)($pkg['status'] ?? '')) ?></td></tr>
    <tr><td>Manual code</td><td><?= h((string)($pkg['manual_code'] ?? '—')) ?></td></tr>
    <tr><td>Target revision</td><td><?= h((string)($pkg['target_revision'] ?? '—')) ?></td></tr>
    <tr><td>Effective date</td><td><?= !empty($pkg['effective_date']) ? h(substr((string)$pkg['effective_date'], 0, 10)) : '—' ?></td></tr>
    <tr><td>Released at</td><td><?= !empty($pkg['released_at']) ? h((string)$pkg['released_at']) : '—' ?></td></tr>
    <tr><td>Released by</td><td><?= h((string)($pkg['released_by_name'] ?? '—')) ?></td></tr>
  </table>
</div>

<h2>Approvals</h2>
<?php if (!is_array($approvals) || $approvals === array()): ?>
  <p class="small">No approvals recorded.</p>
<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Approver</th>
        <th>Role</th>
        <th>Decision</th>
        <th>Decided</th>
        <th>Comments</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($approvals as $a): ?>
        <tr>
          <td><?= h((string)($a['approver_name'] ?? '')) ?></td>
          <td><?= h((string)($a['approver_role'] ?? '—')) ?></td>
          <td><?= h((string)($a['decision'] ?? '')) ?></td>
          <td><?= h((string)($a['decided_at'] ?? '—')) ?></td>
          <td><?= nl2br(h((string)($a['comments'] ?? ''))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Included drafts</h2>
<?php if (!is_array($drafts) || $drafts === array()): ?>
  <p class="small">No drafts attached to this package.</p>
<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Code</th>
        <th>Title</th>
        <th>Manual</th>
        <th>Status</th>
        <th>Published</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($drafts as $d): ?>
        <tr>
          <td><?= h((string)($d['draft_code'] ?? '')) ?></td>
          <td><?= h((string)($d['draft_title'] ?? '')) ?></td>
          <td><?= h((string)($d['manual_label'] ?? ($d['manual_kind'] ?? '—'))) ?></td>
          <td><?= h((string)($d['status'] ?? '')) ?></td>
          <td><?= h((string)($d['approved_at'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php foreach ($drafts as $d): ?>
    <h2 style="margin-top:18px;">
      Draft <?= h((string)($d['draft_code'] ?? '')) ?>
      — <?= h((string)($d['draft_title'] ?? '')) ?>
    </h2>
    <?php if (!empty($d['manual_label']) || !empty($d['manual_ref_id'])): ?>
      <p class="small">
        <strong>Target:</strong>
        <?= h((string)($d['manual_label'] ?? '')) ?>
        <?php if (!empty($d['manual_ref_id'])): ?>
          (<?= h((string)$d['manual_ref_id']) ?>)
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <div class="draft-body"><?= h((string)($d['draft_body'] ?? '')) ?></div>
  <?php endforeach; ?>
<?php endif; ?>
