<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/flight_training/AdminLogbookService.php';

cw_require_admin();

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$service = new AdminLogbookService($pdo);
$logbookId = (int)($_GET['logbook_id'] ?? 0);

try {
    $workspace = $service->loadWorkspace($logbookId);
} catch (Throwable $e) {
    http_response_code(400);
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

$logbook = is_array($workspace['logbook'] ?? null) ? $workspace['logbook'] : array();
$studentName = trim((string)($logbook['student_name'] ?? 'Student'));
$studentEmail = trim((string)($logbook['student_email'] ?? ''));
$requirements = is_array($workspace['requirements'] ?? null) ? $workspace['requirements'] : array();

$pplRequirements = array_values(array_filter($requirements, static function (mixed $row): bool {
    if (!is_array($row)) {
        return false;
    }
    $certificate = strtoupper(trim((string)($row['certificate'] ?? '')));
    $key = strtolower(trim((string)($row['requirement_key'] ?? '')));
    $label = strtolower(trim((string)($row['label'] ?? '')));
    if ($certificate === 'PPL' || str_contains($key, '.ppl.') || str_contains($key, 'private')) {
        return true;
    }
    return str_contains($label, 'private pilot') || str_contains($label, 'ppl');
}));

if ($pplRequirements === array()) {
    $pplRequirements = $requirements;
}

$passCount = 0;
$failCount = 0;
foreach ($pplRequirements as $req) {
    if (strtoupper((string)($req['status'] ?? '')) === 'PASS') {
        $passCount++;
    } else {
        $failCount++;
    }
}

$generatedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i');

/**
 * @param array<string,mixed> $req
 */
function eligibility_unit(array $req): string
{
    $metric = strtolower((string)($req['metric'] ?? ''));
    $key = strtolower((string)($req['requirement_key'] ?? ''));
    $label = strtolower((string)($req['label'] ?? ''));
    $warnings = is_array($req['warnings'] ?? null) ? strtolower(implode(' ', $req['warnings'])) : '';
    if (str_contains($metric, 'distance') || str_contains($key, 'distance') || str_contains($label, 'distance') || str_contains($label, 'nm')) {
        return 'nm';
    }
    if (str_contains($metric, 'landing') || str_contains($key, 'landing') || str_contains($label, 'landing') || str_contains($warnings, 'manual assignment')) {
        return 'count';
    }
    return 'h';
}

function eligibility_format_value(mixed $value, string $unit): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    $number = (float)$value;
    if ($unit === 'h') {
        return number_format($number, 1) . ' h';
    }
    if ($unit === 'nm') {
        return number_format($number, 1) . ' NM';
    }
    return (string)(int)round($number);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Private Pilot Eligibility Audit · <?= h($studentName) ?></title>
  <style>
    :root {
      --ink: #102845;
      --muted: #64748b;
      --line: #cbd5e1;
      --pass: #166534;
      --pass-bg: #dcfce7;
      --fail: #991b1b;
      --fail-bg: #fee2e2;
      --paper: #ffffff;
      --wash: #f8fafc;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif;
      color: var(--ink);
      background: #e5e7eb;
    }
    .toolbar {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
      padding: 12px 18px;
      background: #102845;
      color: #fff;
    }
    .toolbar h1 {
      margin: 0;
      font-size: 15px;
      font-weight: 800;
    }
    .toolbar p {
      margin: 3px 0 0;
      font-size: 12px;
      color: #bfdbfe;
    }
    .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 999px;
      padding: 8px 14px;
      font: inherit;
      font-size: 12px;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
      white-space: nowrap;
    }
    .btn-primary { background: #fff; color: #102845; }
    .btn-ghost { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.35); }
    .sheet {
      width: min(980px, calc(100% - 32px));
      margin: 18px auto 40px;
      background: var(--paper);
      border-radius: 18px;
      box-shadow: 0 18px 50px rgba(15, 23, 42, .16);
      overflow: hidden;
    }
    .sheet-head {
      padding: 28px 28px 18px;
      border-bottom: 1px solid var(--line);
      background: linear-gradient(180deg, #fff 0%, var(--wash) 100%);
    }
    .kicker {
      margin: 0 0 6px;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .sheet-head h2 {
      margin: 0;
      font-size: 28px;
      line-height: 1.15;
    }
    .meta {
      margin-top: 14px;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }
    .meta-card {
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
    }
    .meta-label {
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .meta-value {
      margin-top: 4px;
      font-size: 15px;
      font-weight: 800;
    }
    .summary {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 14px;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 5px 10px;
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
    }
    .badge-pass { background: var(--pass-bg); color: var(--pass); }
    .badge-fail { background: var(--fail-bg); color: var(--fail); }
    .badge-neutral { background: #e2e8f0; color: #334155; }
    .sheet-body { padding: 8px 0 18px; }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      padding: 11px 16px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
      text-align: left;
      font-size: 12px;
    }
    th {
      background: var(--wash);
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--muted);
    }
    td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    td.status { white-space: nowrap; }
    .req-label { font-weight: 800; color: var(--ink); }
    .req-key { margin-top: 3px; font-size: 10px; color: var(--muted); }
    .evidence {
      margin-top: 8px;
      padding: 8px 10px;
      border-radius: 10px;
      background: var(--wash);
      border: 1px solid rgba(15, 23, 42, .08);
      color: #334155;
      font-size: 11px;
    }
    .evidence strong { color: var(--ink); }
    .evidence ul {
      margin: 6px 0 0;
      padding: 0;
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .note {
      margin: 0 28px 22px;
      padding: 12px 14px;
      border-radius: 12px;
      background: #eff6ff;
      color: #1e3a8a;
      font-size: 12px;
      line-height: 1.45;
    }
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .sheet {
        width: 100%;
        margin: 0;
        border-radius: 0;
        box-shadow: none;
      }
      .meta { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .badge, .evidence, .note, .meta-card {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      tr { break-inside: avoid; page-break-inside: avoid; }
    }
    @media (max-width: 800px) {
      .meta { grid-template-columns: 1fr 1fr; }
      .sheet { width: calc(100% - 16px); margin: 10px auto 24px; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <div>
      <h1>Private Pilot Eligibility Audit Overview</h1>
      <p><?= h($studentName) ?><?= $studentEmail !== '' ? ' · ' . h($studentEmail) : '' ?></p>
    </div>
    <div class="toolbar-actions">
      <a class="btn btn-ghost" href="/admin/flight_training/logbooks/view.php?logbook_id=<?= (int)$logbookId ?>">Back to Logbook</a>
      <button class="btn btn-primary" type="button" onclick="window.print()">Print Eligibility Audit Overview</button>
    </div>
  </div>

  <article class="sheet">
    <header class="sheet-head">
      <p class="kicker">IPCA Academy · Flight Training · Eligibility Audit</p>
      <h2>Private Pilot Eligibility Requirements</h2>
      <div class="meta">
        <div class="meta-card">
          <div class="meta-label">Student</div>
          <div class="meta-value"><?= h($studentName) ?></div>
        </div>
        <div class="meta-card">
          <div class="meta-label">Logbook</div>
          <div class="meta-value">#<?= (int)$logbookId ?></div>
        </div>
        <div class="meta-card">
          <div class="meta-label">Generated</div>
          <div class="meta-value"><?= h($generatedAt) ?></div>
        </div>
        <div class="meta-card">
          <div class="meta-label">Result</div>
          <div class="meta-value"><?= $failCount === 0 && $passCount > 0 ? 'Eligible' : ($passCount . '/' . count($pplRequirements) . ' pass') ?></div>
        </div>
      </div>
      <div class="summary">
        <span class="badge badge-pass"><?= (int)$passCount ?> pass</span>
        <span class="badge badge-fail"><?= (int)$failCount ?> fail</span>
        <span class="badge badge-neutral"><?= count($pplRequirements) ?> requirements</span>
      </div>
    </header>

    <div class="sheet-body">
      <?php if ($pplRequirements === array()): ?>
        <p class="note">No Private Pilot requirement categories are configured for this logbook.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Requirement</th>
              <th class="num">Required</th>
              <th class="num">Actual</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pplRequirements as $req): ?>
              <?php
                $unit = eligibility_unit($req);
                $status = strtoupper((string)($req['status'] ?? 'FAIL'));
                $pass = $status === 'PASS';
                $required = $req['minimum'] ?? null;
                $actual = $req['value'] ?? 0;
                $evidenceBlocks = is_array($req['evidence'] ?? null) ? $req['evidence'] : array();
                $evidenceEntries = array();
                foreach ($evidenceBlocks as $block) {
                    if (!is_array($block) || !is_array($block['entries'] ?? null)) {
                        continue;
                    }
                    foreach ($block['entries'] as $entry) {
                        if (is_array($entry)) {
                            $evidenceEntries[] = $entry;
                        }
                    }
                }
              ?>
              <tr>
                <td>
                  <div class="req-label"><?= h((string)($req['label'] ?? $req['requirement_key'] ?? 'Requirement')) ?></div>
                  <div class="req-key"><?= h(trim((string)($req['authority'] ?? '') . ' · ' . (string)($req['certificate'] ?? '') . ' · ' . (string)($req['requirement_key'] ?? ''), " ·")) ?></div>
                  <?php if ($evidenceEntries !== array()): ?>
                    <div class="evidence">
                      <strong><?= h((string)($req['evidence_label'] ?? 'Evidence')) ?></strong>
                      <ul>
                        <?php foreach ($evidenceEntries as $entry): ?>
                          <?php
                            $bits = array_values(array_filter(array(
                                (string)($entry['entry_date'] ?? ''),
                                (string)($entry['route'] ?? ''),
                                ((float)($entry['total_flight_time'] ?? 0) > 0) ? number_format((float)$entry['total_flight_time'], 1) . 'h' : '',
                                ((float)($entry['cross_country_distance_nm'] ?? 0) > 0) ? number_format((float)$entry['cross_country_distance_nm'], 1) . ' NM' : '',
                                (string)($entry['aircraft_registration'] ?? ''),
                            )));
                            $detail = $bits !== array() ? implode(' · ', $bits) : ('Entry #' . (int)($entry['id'] ?? 0));
                          ?>
                          <li><?= h($detail) ?><?php if (trim((string)($entry['remarks'] ?? '')) !== ''): ?> — <?= h((string)$entry['remarks']) ?><?php endif; ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php elseif (!empty($req['requires_tagging'])): ?>
                    <div class="evidence">No tagged logbook record yet.</div>
                  <?php elseif (!empty($req['evidence_label'])): ?>
                    <div class="evidence"><?= h((string)$req['evidence_label']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= h(eligibility_format_value($required, $unit)) ?></td>
                <td class="num"><?= h(eligibility_format_value($actual, $unit)) ?></td>
                <td class="status">
                  <span class="badge <?= $pass ? 'badge-pass' : 'badge-fail' ?>"><?= $pass ? 'PASS' : 'FAIL' ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <p class="note">
      This overview mirrors Requirement Verification on the Admin Logbook workspace.
      Use your browser’s Print dialog and choose “Save as PDF” to keep a PDF copy of this eligibility audit.
      Airport-pair cross-country distances are rounded up to whole nautical miles for eligibility checks.
    </p>
  </article>
</body>
</html>
