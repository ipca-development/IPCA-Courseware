<?php
declare(strict_types=1);
/** @var array<string,mixed> $exportData */

if (!isset($exportData) || !is_array($exportData)) {
    throw new RuntimeException('Invalid export payload');
}

$f = $exportData['finding'] ?? array();
$rca = $exportData['rca'] ?? null;
$steps = $exportData['steps'] ?? array();
$caps = $exportData['caps'] ?? array();
$generatedAt = (string)($exportData['generated_at'] ?? '');
?>
<style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #111827; }
h1          { font-size: 16pt; margin: 0 0 6px; color: #1e3c72; }
h2          { font-size: 12pt; margin: 16px 0 6px; color: #1e3c72; border-bottom: 1px solid #cbd5e1; padding-bottom: 2px; }
.meta       { font-size: 9.5pt; color: #6b7280; margin-bottom: 12px; }
.pbox       { background: #f8fafc; border: 1px solid #e5e7eb; padding: 10px 12px; border-radius: 4px; margin-bottom: 10px; }
.table      { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 6px; }
.table th   { text-align: left; background: #f1f5f9; padding: 6px; border: 1px solid #e5e7eb; }
.table td   { vertical-align: top; padding: 6px; border: 1px solid #e5e7eb; }
.small      { font-size: 9pt; color: #6b7280; }
</style>

<h1>Compliance export — Finding, RCA, and CAP</h1>
<div class="meta">Generated <?= h($generatedAt) ?></div>

<h2>Finding (NCR)</h2>
<div class="pbox">
  <div><strong>Code:</strong> <?= h((string)($f['finding_code'] ?? '')) ?></div>
  <div><strong>Title:</strong> <?= h((string)($f['title'] ?? '')) ?></div>
  <div><strong>Status:</strong> <?= h((string)($f['status'] ?? '')) ?>
    &nbsp;·&nbsp; <strong>Classification:</strong> <?= h((string)($f['classification'] ?? '')) ?>
    &nbsp;·&nbsp; <strong>Severity:</strong> <?= h((string)($f['severity'] ?? '')) ?></div>
  <?php if (!empty($f['reference'])): ?>
    <div><strong>Authority reference:</strong> <?= h((string)$f['reference']) ?></div>
  <?php endif; ?>
  <div class="small" style="margin-top:6px;"><strong>Raised:</strong> <?= h(substr((string)($f['raised_date'] ?? ''), 0, 10)) ?>
    <?php if (!empty($f['target_date'])): ?>
      &nbsp;·&nbsp; <strong>Target:</strong> <?= h(substr((string)$f['target_date'], 0, 10)) ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($f['regulation_summary'])): ?>
  <div class="pbox">
    <div class="small"><strong>Regulation summary</strong></div>
    <div><?= nl2br(h((string)$f['regulation_summary'])) ?></div>
  </div>
<?php endif; ?>

<div class="pbox">
  <div class="small"><strong>Description</strong></div>
  <div><?= nl2br(h((string)($f['description'] ?? ''))) ?></div>
</div>

<h2>Root cause analysis</h2>
<?php if (!is_array($rca)): ?>
  <p class="small">No RCA record for this finding.</p>
<?php else: ?>
  <?php if (!empty($rca['locked_at'])): ?>
    <p class="small">RCA locked<?php
      $an = (string)($rca['approved_by_name'] ?? '');
      echo $an !== '' ? ' — approved by ' . h($an) : '';
    ?>.</p>
  <?php endif; ?>
  <?php if ($steps === array()): ?>
    <p class="small">No 5-Whys steps stored.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th style="width:8%;">#</th><th style="width:42%;">Question</th><th>Answer</th></tr></thead>
      <tbody>
        <?php foreach ($steps as $s): ?>
          <tr>
            <td><?= (int)($s['whyNumber'] ?? 0) ?></td>
            <td><?= nl2br(h((string)($s['question'] ?? ''))) ?></td>
            <td><?= nl2br(h((string)($s['answer'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if (!empty($rca['root_cause_text'])): ?>
    <div class="pbox" style="margin-top:10px;">
      <div class="small"><strong>Root cause statement</strong></div>
      <div><?= nl2br(h((string)$rca['root_cause_text'])) ?></div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<h2>Corrective actions (CAP)</h2>
<?php if (!is_array($caps) || $caps === array()): ?>
  <p class="small">No corrective actions recorded for this finding.</p>
<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Code</th>
        <th>Type</th>
        <th>Status</th>
        <th>Due</th>
        <th>Title / description</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($caps as $c): ?>
        <tr>
          <td style="font-size:8.5pt;"><?= h((string)($c['action_code'] ?? '')) ?></td>
          <td><?= h((string)($c['action_type'] ?? '')) ?></td>
          <td><?= h((string)($c['status'] ?? '')) ?></td>
          <td><?= !empty($c['due_date']) ? h(substr((string)$c['due_date'], 0, 10)) : '—' ?></td>
          <td>
            <strong><?= h((string)($c['title'] ?? '')) ?></strong><br>
            <?= nl2br(h((string)($c['description'] ?? ''))) ?>
            <?php if (!empty($c['responsible_name'])): ?>
              <div class="small" style="margin-top:4px;">Owner: <?= h((string)$c['responsible_name']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if (!empty($f['cap_selected_option']) || !empty($f['cap_selected_effort'])): ?>
  <p class="small"><strong>Selected CAP option (finding):</strong>
    <?= h((string)($f['cap_selected_option'] ?? '')) ?>
    <?php if (!empty($f['cap_selected_effort'])): ?>
      (<?= h((string)$f['cap_selected_effort']) ?>)
    <?php endif; ?>
  </p>
<?php endif; ?>
