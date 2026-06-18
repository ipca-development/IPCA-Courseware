<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/flight_training/AdminLogbookService.php';

cw_require_admin();

$service = new AdminLogbookService($pdo);
$logbookId = (int)($_GET['logbook_id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? 'easa')));
if (!in_array($format, array('easa', 'faa'), true)) {
    $format = 'easa';
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

try {
    $workspace = $service->loadWorkspace($logbookId);
} catch (Throwable $e) {
    http_response_code(400);
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

$entries = array_values(array_filter(
    $workspace['entries'] ?? array(),
    static fn (mixed $entry): bool => is_array($entry) && strtolower((string)($entry['review_status'] ?? '')) !== 'deleted'
));
$chunks = array_chunk($entries, 25);
if ($chunks === array()) {
    $chunks = array(array());
}

$logoText = $format === 'faa' ? 'FAA LOGBOOK' : 'POOLEY’S';
$title = strtoupper($format) . ' Printable Logbook';
$renderError = '';

function pval(mixed $value, int $decimals = 2): string
{
    $number = (float)$value;
    if (abs($number) < 0.005) {
        return '';
    }
    return number_format($number, $decimals, '.', '');
}

function ptime(mixed $value): string
{
    $raw = trim((string)($value ?? ''));
    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m) !== 1) {
        return '';
    }
    return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
}

function pdate(mixed $value): string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('d/m/y', $ts);
}

function sourceValue(array $entry, array $keys): string
{
    $source = json_decode((string)($entry['source_json'] ?? '{}'), true);
    if (!is_array($source)) {
        return '';
    }
    foreach ($keys as $key) {
        $value = trim((string)($source[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function missionCode(array $entry): string
{
    $mission = sourceValue($entry, array('mission_code', 'sc_code', 'scenario_code'));
    if ($mission !== '') {
        return $mission;
    }
    if (preg_match('/Mission:\s*([^·]+)/i', (string)($entry['remarks'] ?? ''), $m) === 1) {
        return trim($m[1]);
    }
    return '';
}

function instructorEndorsement(array $entry): string
{
    $name = trim((string)($entry['instructor_name'] ?? ''));
    $license = sourceValue($entry, array('instructor_license', 'instructor_licence', 'cfi_number', 'license_nr', 'licence_nr'));
    $expires = sourceValue($entry, array('instructor_license_expires', 'instructor_licence_expires', 'cfi_expiration', 'license_expiration'));
    $parts = array_filter(array(
        $name,
        $license !== '' ? 'Lic ' . $license : '',
        $expires !== '' ? 'Exp ' . $expires : '',
    ));
    return implode(' · ', $parts);
}

function pageTotals(array $entries): array
{
    $totals = array(
        'total' => 0.0,
        'se' => 0.0,
        'me' => 0.0,
        'night' => 0.0,
        'ifr' => 0.0,
        'pic' => 0.0,
        'copilot' => 0.0,
        'dual' => 0.0,
        'instructor' => 0.0,
        'if' => 0.0,
        'nav' => 0.0,
        'day_landings' => 0.0,
        'night_landings' => 0.0,
    );
    foreach ($entries as $entry) {
        $totals['total'] += (float)($entry['total_flight_time'] ?? 0) - (float)($entry['fnpt_simulator_time'] ?? 0);
        $totals['se'] += (float)($entry['single_engine_time'] ?? 0);
        $totals['me'] += (float)($entry['multi_engine_time'] ?? 0);
        $totals['night'] += (float)($entry['night_time'] ?? 0);
        $totals['ifr'] += (float)($entry['instrument_time'] ?? 0);
        $totals['pic'] += (float)($entry['pic_time'] ?? 0);
        $totals['copilot'] += (float)($entry['copilot_time'] ?? 0);
        $totals['dual'] += (float)($entry['dual_received_time'] ?? 0);
        $totals['instructor'] += (float)($entry['instructor_time'] ?? 0);
        $totals['if'] += (float)($entry['basic_instrument_flying_time'] ?? 0);
        $totals['nav'] += (float)($entry['cross_country_time'] ?? 0);
        $totals['day_landings'] += (float)((int)($entry['day_landings'] ?? 0));
        $totals['night_landings'] += (float)((int)($entry['night_landings'] ?? 0));
    }
    return $totals;
}

function addTotals(array $a, array $b): array
{
    foreach ($b as $key => $value) {
        $a[$key] = (float)($a[$key] ?? 0) + (float)$value;
    }
    return $a;
}

function leftRow(array $entry): string
{
    return '<tr>'
        . '<td>' . h(pdate($entry['entry_date'] ?? '')) . '</td>'
        . '<td>' . h((string)($entry['departure_airport'] ?? '')) . '</td>'
        . '<td>' . h(ptime($entry['departure_time'] ?? '')) . '</td>'
        . '<td>' . h((string)($entry['arrival_airport'] ?? '')) . '</td>'
        . '<td>' . h(ptime($entry['arrival_time'] ?? '')) . '</td>'
        . '<td>' . h((string)($entry['aircraft_type'] ?? '')) . '</td>'
        . '<td>' . h((string)($entry['aircraft_registration'] ?? '')) . '</td>'
        . '<td>' . h(pval($entry['single_engine_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['multi_engine_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['total_flight_time'] ?? 0)) . '</td>'
        . '<td>' . h((string)($entry['instructor_name'] ?? '')) . '</td>'
        . '<td>' . h((string)((int)($entry['day_landings'] ?? 0) ?: '')) . '</td>'
        . '<td>' . h((string)((int)($entry['night_landings'] ?? 0) ?: '')) . '</td>'
        . '</tr>';
}

function rightRow(array $entry): string
{
    $remarks = trim(implode(' · ', array_filter(array(missionCode($entry), instructorEndorsement($entry)))));
    return '<tr>'
        . '<td>' . h(pval($entry['night_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['instrument_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['pic_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['copilot_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['dual_received_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['instructor_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['basic_instrument_flying_time'] ?? 0)) . '</td>'
        . '<td>' . h(pval($entry['cross_country_time'] ?? 0)) . '</td>'
        . '<td class="remarks">' . h($remarks) . '</td>'
        . '</tr>';
}

function blankRows(int $count, string $side): string
{
    $cells = $side === 'left' ? 13 : 9;
    $html = '';
    for ($i = 0; $i < $count; $i++) {
        $html .= '<tr>' . str_repeat('<td></td>', $cells) . '</tr>';
    }
    return $html;
}

function leftTotalsBox(array $pageTotals, array $previousTotals, array $runningTotals): string
{
    return '<div class="totals-box totals-box-left"><table>'
        . '<colgroup><col class="label"><col class="total"><col class="ldg"><col class="ldg"></colgroup>'
        . totalsRow('Total these pages', pval($pageTotals['total'] ?? 0), pval($pageTotals['day_landings'] ?? 0, 0), pval($pageTotals['night_landings'] ?? 0, 0))
        . totalsRow('Total from previous pages', pval($previousTotals['total'] ?? 0), pval($previousTotals['day_landings'] ?? 0, 0), pval($previousTotals['night_landings'] ?? 0, 0))
        . totalsRow('Total Time', pval($runningTotals['total'] ?? 0), pval($runningTotals['day_landings'] ?? 0, 0), pval($runningTotals['night_landings'] ?? 0, 0))
        . '</table></div>';
}

function rightTotalsBox(array $pageTotals, array $previousTotals, array $runningTotals): string
{
    return '<div class="totals-box totals-box-right"><table>'
        . '<colgroup><col class="label"><col class="night"><col class="ifr"><col class="pic"><col class="copilot"><col class="dual"><col class="instr"><col class="if"><col class="nav"></colgroup>'
        . rightTotalsRow('Total these pages', $pageTotals)
        . rightTotalsRow('Total from previous pages', $previousTotals)
        . rightTotalsRow('Total Time', $runningTotals)
        . '</table></div>';
}

function totalsRow(string $label, string $total, string $dayLandings, string $nightLandings): string
{
    return '<tr><th>' . h($label) . '</th><td>' . h($total) . '</td><td>' . h($dayLandings) . '</td><td>' . h($nightLandings) . '</td></tr>';
}

function rightTotalsRow(string $label, array $totals): string
{
    return '<tr><th>' . h($label) . '</th>'
        . '<td>' . h(pval($totals['night'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['ifr'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['pic'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['copilot'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['dual'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['instructor'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['if'] ?? 0)) . '</td>'
        . '<td>' . h(pval($totals['nav'] ?? 0)) . '</td></tr>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($title) ?></title>
<style>
@page{size:landscape;margin:0}
*{box-sizing:border-box}
body{margin:0;background:#e5e7eb;color:#111827;font-family:Arial,Helvetica,sans-serif}
.screen-tools{position:sticky;top:0;z-index:10;display:flex;gap:8px;align-items:center;padding:10px 14px;background:#102845;color:#fff}
.screen-tools button{border:0;border-radius:999px;padding:8px 12px;font-weight:800;cursor:pointer}
.print-error{max-width:980px;margin:16px auto;padding:14px 16px;border:1px solid #fecdd3;border-radius:12px;background:#fff1f2;color:#991b1b;font-weight:700}
.book-page{width:240mm;height:210mm;margin:12mm auto;padding:8mm 0 0;background:#fff;page-break-after:always;position:relative}
.page-head{width:240mm;height:13mm;display:flex;align-items:flex-start;justify-content:space-between;font-size:11px;letter-spacing:.08em}
.logo{font-size:16px;letter-spacing:.35em;font-weight:500}.right-logo{text-align:right}
.meta{display:flex;gap:36mm;font-size:9px;letter-spacing:0}
table{border-collapse:collapse;table-layout:fixed;width:240mm}
th,td{border:0.25mm solid #111;text-align:center;vertical-align:middle;padding:0 1mm;font-size:7px;line-height:1.05;font-weight:400;overflow:hidden}
thead{height:16mm}thead tr{height:5.333mm}tbody tr{height:6.92mm}
.log-body{height:173mm}.log-body td{height:6.92mm}
.main-title{font-size:8px;font-weight:700}.sub{font-size:6.5px}.remarks{text-align:left;font-size:6.4px}
.totals-box{position:absolute;top:194mm;width:143mm;height:16mm;z-index:3;background:#fff}
.totals-box table{width:143mm;height:16mm;background:#fff}.totals-box th,.totals-box td{height:5.333mm;font-size:6.6px;background:#fff;font-weight:400}
.totals-box th{text-align:center}.totals-box td{font-variant-numeric:tabular-nums}
.totals-box-left{left:94.5mm}.totals-box-left col.label{width:69.5mm}.totals-box-left col.total{width:49mm}.totals-box-left col.ldg{width:12.25mm}
.totals-box-right{left:97mm}.totals-box-right col.label{width:30mm}.totals-box-right col.night{width:12.4mm}.totals-box-right col.ifr{width:12.4mm}.totals-box-right col.pic{width:12.5mm}.totals-box-right col.copilot{width:12.5mm}.totals-box-right col.dual{width:12.5mm}.totals-box-right col.instr{width:12.5mm}.totals-box-right col.if{width:19.85mm}.totals-box-right col.nav{width:18.85mm}
.signature{position:absolute;left:14mm;right:10mm;bottom:8mm;font-size:10px;text-align:center}
.signature .line{display:inline-block;width:82mm;border-bottom:0.25mm dotted #111}
.left col.c1{width:18mm}.left col.c2{width:12.25mm}.left col.c3{width:12.25mm}.left col.c4{width:12.25mm}.left col.c5{width:12.25mm}.left col.c6{width:27.5mm}.left col.c7{width:27.5mm}.left col.c8{width:12.75mm}.left col.c9{width:12.75mm}.left col.c10{width:16.5mm}.left col.c11{width:49mm}.left col.c12{width:13.5mm}.left col.c13{width:13.5mm}
.right col.c1{width:24.75mm}.right col.c2{width:24.75mm}.right col.c3{width:21.125mm}.right col.c4{width:21.125mm}.right col.c5{width:21.125mm}.right col.c6{width:21.125mm}.right col.c7{width:13.25mm}.right col.c8{width:13.25mm}.right col.c9{width:79.5mm}
@media print{body{background:#fff}.screen-tools{display:none}.book-page{margin:0;padding-top:8mm;break-after:page}}
</style>
</head>
<body>
<div class="screen-tools">
  <strong><?= h($title) ?></strong>
  <button onclick="window.print()">Print</button>
  <span>Use browser print at 100% scale. Each side is fixed at 240mm wide. Rows: <?= count($entries) ?></span>
</div>
<?php
try {
    $previousTotals = array();
    foreach ($chunks as $pageIndex => $chunk):
        $pageTotals = pageTotals($chunk);
        $runningTotals = addTotals($previousTotals, $pageTotals);
        $rows = implode('', array_map('leftRow', $chunk)) . blankRows(max(0, 25 - count($chunk)), 'left');
?>
<section class="book-page">
  <div class="page-head"><div class="logo"><?= h($logoText) ?></div><div class="meta"><span>Medical Expires:</span><span>Class/Type Rating Expires:</span></div></div>
  <table class="left">
    <colgroup><?php for ($i = 1; $i <= 13; $i++): ?><col class="c<?= $i ?>"><?php endfor; ?></colgroup>
    <thead>
      <tr><th colspan="1">1</th><th colspan="2">2</th><th colspan="2">3</th><th colspan="2">4</th><th colspan="2">5</th><th>6</th><th>7</th><th colspan="2">8</th></tr>
      <tr><th rowspan="2">Date<br><span class="sub">(dd/mm/yy)</span></th><th colspan="2">Departure</th><th colspan="2">Arrival</th><th colspan="2">Aircraft</th><th colspan="2">Single Pilot</th><th rowspan="2">Multi<br>Pilot</th><th rowspan="2">Total Time<br>of Flight</th><th colspan="2">Landings</th></tr>
      <tr><th>Place</th><th>Time</th><th>Place</th><th>Time</th><th>Type</th><th>Registration</th><th>SE</th><th>ME</th><th>Day</th><th>Night</th></tr>
    </thead>
    <tbody class="log-body"><?= $rows ?></tbody>
  </table>
  <?= leftTotalsBox($pageTotals, $previousTotals, $runningTotals) ?>
</section>
<?php
        $rightRows = implode('', array_map('rightRow', $chunk)) . blankRows(max(0, 25 - count($chunk)), 'right');
?>
<section class="book-page">
  <div class="page-head"><div></div><div class="logo right-logo"><?= h($logoText) ?></div></div>
  <table class="right">
    <colgroup><?php for ($i = 1; $i <= 9; $i++): ?><col class="c<?= $i ?>"><?php endfor; ?></colgroup>
    <thead>
      <tr><th colspan="2">9</th><th colspan="4">10</th><th colspan="2">11</th><th>12</th></tr>
      <tr><th colspan="2">Operational Condition Time</th><th colspan="4">Pilot Function Time</th><th colspan="2">Other Flying</th><th rowspan="2">Remarks and Endorsements</th></tr>
      <tr><th>Night</th><th>IFR</th><th>PIC</th><th>Co-Pilot</th><th>Dual</th><th>Instructor</th><th>IF</th><th>NAV</th></tr>
    </thead>
    <tbody class="log-body"><?= $rightRows ?></tbody>
  </table>
  <?= rightTotalsBox($pageTotals, $previousTotals, $runningTotals) ?>
  <div class="signature">I certify that the entries in this log are true: <span class="line"></span> (Pilot's Signature).</div>
</section>
<?php
        $previousTotals = $runningTotals;
    endforeach;
} catch (Throwable $e) {
    echo '<div class="print-error">Printable logbook rendering failed: ' . h($e->getMessage()) . '</div>';
}
?>
</body>
</html>
