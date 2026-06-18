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
$rowsPerSpread = 25;
$chunks = array_chunk($entries, $rowsPerSpread);
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
.screen-tools{position:sticky;top:0;z-index:10;display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;background:#102845;color:#fff}
.screen-tools button,.screen-tools select{border:0;border-radius:999px;padding:8px 12px;font-weight:800;cursor:pointer}
.screen-tools select{background:#fff;color:#102845}
.screen-tools .muted{color:#bfdbfe;font-size:12px}
.print-error{max-width:980px;margin:16px auto;padding:14px 16px;border:1px solid #fecdd3;border-radius:12px;background:#fff1f2;color:#991b1b;font-weight:700}
.print-stage{min-height:calc(100vh - 58px);display:flex;align-items:flex-start;justify-content:center;padding:22px;background:radial-gradient(circle at center,#f8fafc 0,#e5e7eb 70%)}
.paper-sheet{position:relative;background:#f7f8fb;border-radius:18px;box-shadow:inset 0 0 0 1px rgba(15,23,42,.08),0 20px 70px rgba(15,23,42,.22);overflow:hidden;cursor:grab}
.paper-sheet.is-dragging{cursor:grabbing}
.paper-sheet[data-paper="a4"]{width:297mm;height:210mm;--page-w:297mm;--page-h:210mm;--left-table-x:14mm;--right-table-x:43mm}
.paper-sheet[data-paper="letter"]{width:279.4mm;height:215.9mm;--page-w:279.4mm;--page-h:215.9mm;--left-table-x:12mm;--right-table-x:27.4mm}
.book-spread{position:absolute;left:50%;top:50%;display:none;width:calc(var(--page-w) * 2);height:var(--page-h);transform:translate(-50%,-50%) scale(var(--spread-scale,.5));transform-origin:center;filter:drop-shadow(0 12px 26px rgba(15,23,42,.2));perspective:1600px;transform-style:preserve-3d;opacity:0;transition:opacity .25s ease}
.book-spread.is-active{display:flex;opacity:1;z-index:2}
.book-spread.is-fading{display:flex;opacity:0;z-index:2;pointer-events:none}
.book-spread::before{content:"";position:absolute;left:50%;top:4mm;bottom:4mm;width:2.2mm;transform:translateX(-50%);background:linear-gradient(90deg,rgba(15,23,42,.28),rgba(255,255,255,.75),rgba(15,23,42,.26));z-index:6;border-radius:999px;box-shadow:0 0 7mm rgba(15,23,42,.2)}
.book-page{width:var(--page-w);height:var(--page-h);margin:0;background:#fffdf7;position:relative;flex:0 0 auto;overflow:hidden;border:0.25mm solid rgba(15,23,42,.16);--header-top:5mm;--grid-top:21mm;--grid-head-h:16mm;--grid-body-h:145mm;--row-h:5.8mm;--totals-top:184mm;--totals-h:14mm;--signature-top:201mm;--signature-h:6mm}
.book-page::after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(135deg,rgba(255,255,255,.38),rgba(15,23,42,0) 45%,rgba(15,23,42,.035));z-index:5}
.book-page-left{border-radius:2mm 0 0 2mm;box-shadow:inset -18mm 0 30mm rgba(15,23,42,.1),inset 0 0 0 .35mm rgba(15,23,42,.08)}
.book-page-right{border-radius:0 2mm 2mm 0;box-shadow:inset 18mm 0 30mm rgba(15,23,42,.09),inset 0 0 0 .35mm rgba(15,23,42,.08)}
.book-page-left::before,.book-page-right::before{content:"";position:absolute;top:0;bottom:0;width:1.1mm;background:rgba(15,23,42,.12);z-index:6}.book-page-left::before{left:0}.book-page-right::before{right:0}
.page-head{position:absolute;left:var(--table-x);top:var(--header-top);width:240mm;height:10mm;display:flex;align-items:flex-start;justify-content:space-between;font-size:11px;letter-spacing:.08em}
.logo{font-size:16px;letter-spacing:.35em;font-weight:500}.right-logo{text-align:right}
.meta{display:flex;gap:36mm;font-size:9px;letter-spacing:0}
table{border-collapse:collapse;table-layout:fixed;width:240mm}
.book-page>table{position:absolute;left:var(--table-x);top:var(--grid-top)}
.book-page-left{--table-x:var(--left-table-x)}.book-page-right{--table-x:var(--right-table-x)}
th,td{border:0.25mm solid #111;text-align:center;vertical-align:middle;padding:0 1mm;font-size:7px;line-height:1.05;font-weight:400;overflow:hidden}
thead{height:var(--grid-head-h)}thead tr{height:5.333mm}tbody tr{height:var(--row-h)}
.log-body{height:var(--grid-body-h)}.log-body td{height:var(--row-h)}
.main-title{font-size:8px;font-weight:700}.sub{font-size:6.5px}.remarks{text-align:left;font-size:6.4px}
.totals-box{position:absolute;top:var(--totals-top);width:143mm;height:var(--totals-h);z-index:7;background:#fffdf7}
.totals-box table{width:143mm;height:var(--totals-h);background:#fffdf7}.totals-box th,.totals-box td{height:calc(var(--totals-h) / 3);font-size:6.6px;background:#fffdf7;font-weight:400}
.totals-box th{text-align:center}.totals-box td{font-variant-numeric:tabular-nums}
.totals-box-left{left:calc(var(--table-x) + 94.5mm)}.totals-box-left col.label{width:69.5mm}.totals-box-left col.total{width:49mm}.totals-box-left col.ldg{width:12.25mm}
.totals-box-right{left:calc(var(--table-x) + 97mm)}.totals-box-right col.label{width:30mm}.totals-box-right col.night{width:12.4mm}.totals-box-right col.ifr{width:12.4mm}.totals-box-right col.pic{width:12.5mm}.totals-box-right col.copilot{width:12.5mm}.totals-box-right col.dual{width:12.5mm}.totals-box-right col.instr{width:12.5mm}.totals-box-right col.if{width:19.85mm}.totals-box-right col.nav{width:18.85mm}
.signature{position:absolute;left:14mm;right:10mm;top:var(--signature-top);height:var(--signature-h);font-size:10px;text-align:center;z-index:7}
.signature .line{display:inline-block;width:82mm;border-bottom:0.25mm dotted #111}
.left col.c1{width:18mm}.left col.c2{width:12.25mm}.left col.c3{width:12.25mm}.left col.c4{width:12.25mm}.left col.c5{width:12.25mm}.left col.c6{width:27.5mm}.left col.c7{width:27.5mm}.left col.c8{width:12.75mm}.left col.c9{width:12.75mm}.left col.c10{width:16.5mm}.left col.c11{width:49mm}.left col.c12{width:13.5mm}.left col.c13{width:13.5mm}
.right col.c1{width:24.75mm}.right col.c2{width:24.75mm}.right col.c3{width:21.125mm}.right col.c4{width:21.125mm}.right col.c5{width:21.125mm}.right col.c6{width:21.125mm}.right col.c7{width:13.25mm}.right col.c8{width:13.25mm}.right col.c9{width:79.5mm}
@media print{body{background:#fff}.screen-tools{display:none}.print-stage{display:block;padding:0;background:#fff}.paper-sheet{width:auto!important;height:auto!important;box-shadow:none;border-radius:0;background:#fff;overflow:visible;cursor:auto}.book-spread{position:relative;left:auto;top:auto;display:block!important;width:var(--page-w);height:auto;transform:none!important;filter:none;perspective:none;opacity:1;transition:none}.book-spread::before,.book-page::after,.book-page::before{display:none}.book-page{display:block;background:#fff;border:0;box-shadow:none;border-radius:0;break-after:page}}
</style>
</head>
<body>
<div class="screen-tools">
  <strong><?= h($title) ?></strong>
  <button onclick="window.print()">Print</button>
  <button type="button" id="prevSpread">Previous</button>
  <button type="button" id="nextSpread">Next</button>
  <button type="button" id="zoomOut">Zoom -</button>
  <button type="button" id="zoomIn">Zoom +</button>
  <button type="button" id="fitWidth">Fit Width</button>
  <button type="button" id="fitSpread">Fit Full Spread</button>
  <button type="button" id="resetZoom">100%</button>
  <select id="paperSelect" aria-label="Paper size">
    <option value="a4">A4 landscape</option>
    <option value="letter">US Letter landscape</option>
  </select>
  <span class="muted">Spread <span id="spreadNow">1</span>/<span id="spreadTotal"><?= count($chunks) ?></span> · Rows: <?= count($entries) ?> · <?= (int)$rowsPerSpread ?> rows/page · fixed 240mm logbook pages</span>
</div>
<main class="print-stage">
<div class="paper-sheet" id="paperSheet" data-paper="a4">
<?php
try {
    $previousTotals = array();
    foreach ($chunks as $pageIndex => $chunk):
        $pageTotals = pageTotals($chunk);
        $runningTotals = addTotals($previousTotals, $pageTotals);
        $rows = implode('', array_map('leftRow', $chunk)) . blankRows(max(0, $rowsPerSpread - count($chunk)), 'left');
?>
<div class="book-spread<?= $pageIndex === 0 ? ' is-active' : '' ?>" data-spread="<?= (int)$pageIndex ?>">
<section class="book-page book-page-left">
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
        $rightRows = implode('', array_map('rightRow', $chunk)) . blankRows(max(0, $rowsPerSpread - count($chunk)), 'right');
?>
<section class="book-page book-page-right">
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
</div>
<?php
        $previousTotals = $runningTotals;
    endforeach;
} catch (Throwable $e) {
    echo '<div class="print-error">Printable logbook rendering failed: ' . h($e->getMessage()) . '</div>';
}
?>
</div>
</main>
<script>
(function(){
  const sheet = document.getElementById('paperSheet');
  const spreads = Array.from(document.querySelectorAll('.book-spread'));
  const now = document.getElementById('spreadNow');
  const select = document.getElementById('paperSelect');
  let current = 0;
  let zoomMode = 'fit-spread';
  let zoom = 1;
  let pan = {x:0, y:0};
  let drag = null;
  function spreadSizeMm(){
    return sheet.dataset.paper === 'letter' ? {w:558.8, h:215.9} : {w:594, h:210};
  }
  function baseScale(mode){
    const rect = sheet.getBoundingClientRect();
    const pxPerMm = 96 / 25.4;
    const size = spreadSizeMm();
    if(mode === 'fit-width') return (rect.width - 24) / (size.w * pxPerMm);
    if(mode === 'actual') return 1;
    return Math.min((rect.width - 24) / (size.w * pxPerMm), (rect.height - 18) / (size.h * pxPerMm));
  }
  function currentScale(){
    return Math.max(0.08, Math.min(2.2, baseScale(zoomMode) * zoom));
  }
  function applyTransform(){
    const scale = currentScale();
    sheet.style.setProperty('--spread-scale', String(scale));
    spreads.forEach(spread => {
      if(spread.classList.contains('is-active') || spread.classList.contains('is-fading')) {
        spread.style.transform = `translate(-50%, -50%) translate(${pan.x}px, ${pan.y}px) scale(${scale})`;
      }
    });
  }
  function setZoomMode(mode, nextZoom = 1){
    zoomMode = mode;
    zoom = nextZoom;
    pan = {x:0, y:0};
    applyTransform();
  }
  function show(index, direction = 0){
    const target = Math.max(0, Math.min(spreads.length - 1, index));
    if(target === current) return;
    if(direction !== 0) {
      fadeTo(target);
      return;
    }
    current = target;
    spreads.forEach((spread, idx) => {
      spread.classList.toggle('is-active', idx === current);
      spread.classList.remove('is-fading');
      spread.style.transform = '';
    });
    now.textContent = String(current + 1);
    pan = {x:0, y:0};
    applyTransform();
  }
  function fadeTo(target){
    const oldSpread = spreads[current];
    const newSpread = spreads[target];
    if(!oldSpread || !newSpread) return;
    oldSpread.classList.add('is-fading');
    applyTransform();
    window.setTimeout(() => {
      oldSpread.classList.remove('is-active', 'is-fading');
      oldSpread.style.transform = '';
      newSpread.classList.add('is-active');
      current = target;
      now.textContent = String(current + 1);
      pan = {x:0, y:0};
      applyTransform();
    }, 250);
  }
  document.getElementById('prevSpread').addEventListener('click', () => show(current - 1, -1));
  document.getElementById('nextSpread').addEventListener('click', () => show(current + 1, 1));
  document.getElementById('zoomIn').addEventListener('click', () => { zoomMode = zoomMode === 'actual' ? 'fit-spread' : zoomMode; zoom = Math.min(3, zoom * 1.18); applyTransform(); });
  document.getElementById('zoomOut').addEventListener('click', () => { zoomMode = zoomMode === 'actual' ? 'fit-spread' : zoomMode; zoom = Math.max(.35, zoom / 1.18); applyTransform(); });
  document.getElementById('fitWidth').addEventListener('click', () => setZoomMode('fit-width'));
  document.getElementById('fitSpread').addEventListener('click', () => setZoomMode('fit-spread'));
  document.getElementById('resetZoom').addEventListener('click', () => setZoomMode('actual'));
  select.addEventListener('change', () => {
    sheet.dataset.paper = select.value;
    try { localStorage.setItem('ipca.printLogbook.paper', select.value); } catch(err) {}
    requestAnimationFrame(applyTransform);
  });
  sheet.addEventListener('mousedown', event => {
    if(event.button !== 0) return;
    drag = {x:event.clientX, y:event.clientY, startX:pan.x, startY:pan.y};
    sheet.classList.add('is-dragging');
  });
  window.addEventListener('mousemove', event => {
    if(!drag) return;
    pan.x = drag.startX + event.clientX - drag.x;
    pan.y = drag.startY + event.clientY - drag.y;
    applyTransform();
  });
  window.addEventListener('mouseup', () => {
    drag = null;
    sheet.classList.remove('is-dragging');
  });
  sheet.addEventListener('wheel', event => {
    if(!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    zoomMode = zoomMode === 'actual' ? 'fit-spread' : zoomMode;
    zoom = Math.max(.35, Math.min(3, zoom * (event.deltaY < 0 ? 1.08 : .92)));
    applyTransform();
  }, {passive:false});
  try {
    const saved = localStorage.getItem('ipca.printLogbook.paper');
    if(saved === 'letter' || saved === 'a4') {
      select.value = saved;
      sheet.dataset.paper = saved;
    }
  } catch(err) {}
  window.addEventListener('resize', applyTransform);
  window.addEventListener('keydown', event => {
    if(event.key === 'ArrowLeft') show(current - 1, -1);
    if(event.key === 'ArrowRight') show(current + 1, 1);
  });
  show(0);
})();
</script>
</body>
</html>
