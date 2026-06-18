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
$blankMode = in_array(strtolower(trim((string)($_GET['blank'] ?? ''))), array('1', 'true', 'yes'), true);

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
$renderChunks = $blankMode ? array(array()) : $chunks;

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

function svgLine(float $x1, float $y1, float $x2, float $y2, string $class = 'thin'): string
{
    return '<line class="' . h($class) . '" x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '"/>';
}

function svgText(float $x, float $y, string $text, string $class = 'body', string $anchor = 'middle'): string
{
    return '<text class="' . h($class) . '" x="' . $x . '" y="' . $y . '" text-anchor="' . h($anchor) . '">' . h($text) . '</text>';
}

function svgMultiline(float $x, float $y, array $lines, string $class = 'head'): string
{
    $out = '<text class="' . h($class) . '" x="' . $x . '" y="' . $y . '" text-anchor="middle">';
    foreach (array_values($lines) as $idx => $line) {
        $out .= '<tspan x="' . $x . '" dy="' . ($idx === 0 ? 0 : 2.8) . '">' . h((string)$line) . '</tspan>';
    }
    return $out . '</text>';
}

function gridLines(array $columns, int $rows = 25): string
{
    $width = array_sum($columns);
    $header = 16.0;
    $body = 173.0;
    $height = $header + $body;
    $rowHeight = $body / $rows;
    $x = 0.0;
    $out = svgLine(0, 0, $width, 0, 'outer') . svgLine(0, $height, $width, $height, 'outer') . svgLine(0, 0, 0, $height, 'outer') . svgLine($width, 0, $width, $height, 'outer');
    foreach ($columns as $idx => $column) {
        $x += (float)$column;
        $class = in_array($idx + 1, array(1, 3, 5, 7, 9, 10, 11, 13), true) ? 'main' : 'sub';
        $out .= svgLine($x, 0, $x, $height, $class);
    }
    foreach (array(5.333, 10.666, 16.0) as $y) {
        $out .= svgLine(0, $y, $width, $y, 'main');
    }
    for ($i = 1; $i < $rows; $i++) {
        $y = $header + $i * $rowHeight;
        $out .= svgLine(0, $y, $width, $y, 'row');
    }
    return $out;
}

function centers(array $columns): array
{
    $centers = array();
    $x = 0.0;
    foreach ($columns as $width) {
        $centers[] = $x + ((float)$width / 2);
        $x += (float)$width;
    }
    return $centers;
}

function leftTemplate(array $entries): string
{
    $columns = array(18, 12.25, 12.25, 12.25, 12.25, 27.5, 27.5, 12.75, 12.75, 16.5, 49, 13.5, 13.5);
    $centers = centers($columns);
    $out = '<svg class="log-template left-template" viewBox="0 0 240 189" preserveAspectRatio="none">';
    $out .= gridLines($columns);
    $out .= svgText(9, 3.4, '1', 'tiny') . svgText(30.25, 3.4, '2', 'tiny') . svgText(54.75, 3.4, '3', 'tiny') . svgText(94.5, 3.4, '4', 'tiny') . svgText(136, 3.4, '5', 'tiny') . svgText(157, 3.4, '6', 'tiny') . svgText(189.75, 3.4, '7', 'tiny') . svgText(226.5, 3.4, '8', 'tiny');
    $out .= svgMultiline($centers[0], 8.0, array('Date', '(dd/mm/yy)'), 'head');
    $out .= svgText(($centers[1] + $centers[2]) / 2, 8.0, 'Departure', 'head');
    $out .= svgText(($centers[3] + $centers[4]) / 2, 8.0, 'Arrival', 'head');
    $out .= svgText(($centers[5] + $centers[6]) / 2, 8.0, 'Aircraft', 'head');
    $out .= svgText(($centers[7] + $centers[8]) / 2, 8.0, 'Single Pilot', 'head');
    $out .= svgMultiline($centers[9], 7.6, array('Total Time', 'of Flight'), 'head');
    $out .= svgMultiline($centers[10], 7.6, array('Name Pilot', 'in Command'), 'head');
    $out .= svgText(($centers[11] + $centers[12]) / 2, 8.0, 'Landings', 'head');
    foreach (array(1 => 'Place', 2 => 'Time', 3 => 'Place', 4 => 'Time', 5 => 'Type', 6 => 'Registration', 7 => 'SE', 8 => 'ME', 11 => 'Day', 12 => 'Night') as $idx => $label) {
        $out .= svgText($centers[$idx], 13.6, $label, 'head');
    }
    $rowHeight = 173.0 / 25;
    foreach (array_slice($entries, 0, 25) as $idx => $entry) {
        $y = 16.0 + $idx * $rowHeight + ($rowHeight / 2) + 0.8;
        $values = array(
            pdate($entry['entry_date'] ?? ''),
            (string)($entry['departure_airport'] ?? ''),
            ptime($entry['departure_time'] ?? ''),
            (string)($entry['arrival_airport'] ?? ''),
            ptime($entry['arrival_time'] ?? ''),
            (string)($entry['aircraft_type'] ?? ''),
            (string)($entry['aircraft_registration'] ?? ''),
            pval($entry['single_engine_time'] ?? 0),
            pval($entry['multi_engine_time'] ?? 0),
            '',
            pval($entry['total_flight_time'] ?? 0),
            (string)($entry['instructor_name'] ?? ''),
            (string)((int)($entry['day_landings'] ?? 0) ?: ''),
            (string)((int)($entry['night_landings'] ?? 0) ?: ''),
        );
        unset($values[9]);
        $map = array(0,1,2,3,4,5,6,7,8,10,11,12,13);
        foreach ($map as $colIdx => $valueIdx) {
            $out .= svgText($centers[$colIdx], $y, (string)$values[$valueIdx], 'body');
        }
    }
    return $out . '</svg>';
}

function rightTemplate(array $entries): string
{
    $columns = array(24.75, 24.75, 21.125, 21.125, 21.125, 21.125, 13.25, 13.25, 89.5);
    $centers = centers($columns);
    $out = '<svg class="log-template right-template" viewBox="0 0 240 189" preserveAspectRatio="none">';
    $out .= gridLines($columns);
    $out .= svgText(24.75, 3.4, '9', 'tiny') . svgText(91.75, 3.4, '10', 'tiny') . svgText(147.25, 3.4, '11', 'tiny') . svgText(195.25, 3.4, '12', 'tiny');
    $out .= svgText(24.75, 8.0, 'Operational Condition Time', 'head');
    $out .= svgText(91.75, 8.0, 'Pilot Function Time', 'head');
    $out .= svgText(147.25, 8.0, 'Other Flying', 'head');
    $out .= svgText(195.25, 8.0, 'Remarks and Endorsements', 'head');
    foreach (array(0 => 'Night', 1 => 'IFR', 2 => 'PIC', 3 => 'Co-Pilot', 4 => 'Dual', 5 => 'Instructor', 6 => 'IF', 7 => 'NAV') as $idx => $label) {
        $out .= svgText($centers[$idx], 13.6, $label, 'head');
    }
    $rowHeight = 173.0 / 25;
    foreach (array_slice($entries, 0, 25) as $idx => $entry) {
        $y = 16.0 + $idx * $rowHeight + ($rowHeight / 2) + 0.8;
        $values = array(
            pval($entry['night_time'] ?? 0),
            pval($entry['instrument_time'] ?? 0),
            pval($entry['pic_time'] ?? 0),
            pval($entry['copilot_time'] ?? 0),
            pval($entry['dual_received_time'] ?? 0),
            pval($entry['instructor_time'] ?? 0),
            pval($entry['basic_instrument_flying_time'] ?? 0),
            pval($entry['cross_country_time'] ?? 0),
            trim(implode(' · ', array_filter(array(missionCode($entry), instructorEndorsement($entry))))),
        );
        foreach ($values as $colIdx => $value) {
            $textX = $colIdx === 8 ? 152.0 : $centers[$colIdx];
            $out .= svgText($textX, $y, (string)$value, $colIdx === 8 ? 'remarks-text' : 'body', $colIdx === 8 ? 'start' : 'middle');
        }
    }
    return $out . '</svg>';
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
.screen-tools button,.screen-tools select,.screen-tools .tool-link{border:0;border-radius:999px;padding:8px 12px;font-weight:800;cursor:pointer;text-decoration:none}
.screen-tools select{background:#fff;color:#102845}
.screen-tools .tool-link{background:#e0f2fe;color:#102845}
.screen-tools .muted{color:#bfdbfe;font-size:12px}
.print-error{max-width:980px;margin:16px auto;padding:14px 16px;border:1px solid #fecdd3;border-radius:12px;background:#fff1f2;color:#991b1b;font-weight:700}
.print-stage{min-height:calc(100vh - 58px);display:flex;align-items:flex-start;justify-content:center;padding:22px;background:radial-gradient(circle at center,#f8fafc 0,#e5e7eb 70%)}
.paper-sheet{position:relative;background:#f7f8fb;border-radius:18px;box-shadow:inset 0 0 0 1px rgba(15,23,42,.08),0 20px 70px rgba(15,23,42,.22);overflow:hidden;cursor:grab}
.paper-sheet.is-dragging{cursor:grabbing}
.paper-sheet[data-paper="a4"]{width:297mm;height:210mm}.paper-sheet[data-paper="letter"]{width:279.4mm;height:215.9mm}
.paper-sheet{--page-w:270mm;--page-h:190mm;--grid-w:252mm;--grid-h:158mm;--left-table-x:9mm;--right-table-x:9mm}
.book-spread{position:absolute;left:50%;top:50%;display:none;width:calc(var(--page-w) * 2);height:var(--page-h);transform:translate(-50%,-50%) scale(var(--spread-scale,.5));transform-origin:center;filter:drop-shadow(0 12px 26px rgba(15,23,42,.2));perspective:1600px;transform-style:preserve-3d;opacity:0;transition:opacity .25s ease}
.book-spread.is-active{display:flex;opacity:1;z-index:2}
.book-spread.is-fading{display:flex;opacity:0;z-index:2;pointer-events:none}
.book-spread::before{content:"";position:absolute;left:50%;top:3mm;bottom:3mm;width:.9mm;transform:translateX(-50%);background:linear-gradient(90deg,rgba(15,23,42,.2),rgba(255,255,255,.35),rgba(15,23,42,.18));z-index:6;border-radius:999px;box-shadow:0 0 4mm rgba(15,23,42,.18)}
.book-page{width:var(--page-w);height:var(--page-h);margin:0;background:linear-gradient(110deg,#fffdf4 0%,#fbf7eb 48%,#fffdf6 100%);position:relative;flex:0 0 auto;overflow:hidden;border:0.2mm solid rgba(15,23,42,.14);--header-top:5mm;--grid-top:13mm;--grid-head-h:16mm;--grid-body-h:173mm;--row-h:6.92mm;--totals-top:154mm;--totals-h:16mm;--signature-top:176mm;--signature-h:6mm}
.book-page::after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(135deg,rgba(255,255,255,.26),rgba(15,23,42,0) 46%,rgba(15,23,42,.03));z-index:5}
.book-page-left{border-radius:1.5mm 0 0 1.5mm;box-shadow:inset -10mm 0 18mm rgba(15,23,42,.08),inset 0 0 0 .25mm rgba(15,23,42,.08)}
.book-page-right{border-radius:0 1.5mm 1.5mm 0;box-shadow:inset 10mm 0 18mm rgba(15,23,42,.07),inset 0 0 0 .25mm rgba(15,23,42,.08)}
.book-page-left::before,.book-page-right::before{content:"";position:absolute;top:0;bottom:0;width:.7mm;background:rgba(15,23,42,.1);z-index:6}.book-page-left::before{right:0}.book-page-right::before{left:0}
.page-head{position:absolute;left:var(--table-x);top:var(--header-top);width:var(--grid-w);height:8mm;display:flex;align-items:flex-start;justify-content:space-between;font-size:10px;letter-spacing:.08em}
.logo{font-size:16px;letter-spacing:.35em;font-weight:500}.right-logo{text-align:right}
.meta{display:flex;gap:36mm;font-size:9px;letter-spacing:0}
.book-page-left{--table-x:var(--left-table-x)}.book-page-right{--table-x:var(--right-table-x)}
.log-template{position:absolute;left:var(--table-x);top:var(--grid-top);width:var(--grid-w);height:var(--grid-h);z-index:4;shape-rendering:crispEdges}
.log-template .row{stroke:#111;stroke-width:.13;vector-effect:non-scaling-stroke}
.log-template .sub{stroke:#111;stroke-width:.2;vector-effect:non-scaling-stroke}
.log-template .main{stroke:#111;stroke-width:.3;vector-effect:non-scaling-stroke}
.log-template .outer{stroke:#111;stroke-width:.42;vector-effect:non-scaling-stroke}
.log-template text{font-family:Arial,Helvetica,sans-serif;fill:#111;dominant-baseline:middle}
.log-template .tiny{font-size:2.55px;font-weight:700}
.log-template .head{font-size:2.35px;font-weight:700}
.log-template .body{font-size:1.9px;font-weight:400}
.log-template .remarks-text{font-size:1.65px;font-weight:400}
.totals-box{position:absolute;top:var(--totals-top);width:150mm;height:var(--totals-h);z-index:7;background:transparent}
.totals-box table{border-collapse:collapse;table-layout:fixed;width:150mm;height:var(--totals-h);background:transparent}.totals-box th,.totals-box td{border:0.22mm solid #111;text-align:center;vertical-align:middle;padding:0 .7mm;height:calc(var(--totals-h) / 3);font-size:6px;line-height:1.05;background:transparent;font-weight:400;overflow:hidden}
.totals-box th{text-align:center}.totals-box td{font-variant-numeric:tabular-nums}
.totals-box-left{left:calc(var(--table-x) + 99mm)}.totals-box-left col.label{width:73mm}.totals-box-left col.total{width:51.5mm}.totals-box-left col.ldg{width:12.75mm}
.totals-box-right{left:calc(var(--table-x) + 102mm)}.totals-box-right col.label{width:31mm}.totals-box-right col.night{width:13mm}.totals-box-right col.ifr{width:13mm}.totals-box-right col.pic{width:13mm}.totals-box-right col.copilot{width:13mm}.totals-box-right col.dual{width:13mm}.totals-box-right col.instr{width:13mm}.totals-box-right col.if{width:20.5mm}.totals-box-right col.nav{width:20.5mm}
.signature{position:absolute;left:20mm;right:10mm;top:var(--signature-top);height:var(--signature-h);font-size:8px;text-align:left;z-index:7}
.signature .line{display:inline-block;width:82mm;border-bottom:0.25mm dotted #111}
@media print{body{background:#fff}.screen-tools{display:none}.print-stage{display:block;padding:0;background:#fff}.paper-sheet{width:auto!important;height:auto!important;box-shadow:none;border-radius:0;background:#fff;overflow:visible;cursor:auto}.book-spread{position:relative;left:auto;top:auto;display:block!important;width:calc(var(--page-w) * 2);height:var(--page-h);transform:none!important;filter:none;perspective:none;opacity:1;transition:none;break-after:page}.book-spread::before,.book-page::after,.book-page::before{display:none}.book-page{display:inline-block;background:#fff;border:0;box-shadow:none;border-radius:0;vertical-align:top}}
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
  <a class="tool-link" href="?logbook_id=<?= (int)$logbookId ?>&format=<?= h($format) ?><?= $blankMode ? '' : '&blank=1' ?>"><?= $blankMode ? 'Show Data' : 'Blank Template' ?></a>
  <select id="paperSelect" aria-label="Paper size">
    <option value="a4">A4 landscape</option>
    <option value="letter">US Letter landscape</option>
  </select>
  <span class="muted">Spread <span id="spreadNow">1</span>/<span id="spreadTotal"><?= count($renderChunks) ?></span> · Rows: <?= $blankMode ? 0 : count($entries) ?> · <?= $blankMode ? 'blank calibration mode' : ((int)$rowsPerSpread . ' rows/page') ?> · calibrated physical template</span>
</div>
<main class="print-stage">
<div class="paper-sheet" id="paperSheet" data-paper="a4">
<?php
try {
    $previousTotals = array();
    foreach ($renderChunks as $pageIndex => $chunk):
        $pageTotals = pageTotals($chunk);
        $runningTotals = addTotals($previousTotals, $pageTotals);
?>
<div class="book-spread<?= $pageIndex === 0 ? ' is-active' : '' ?>" data-spread="<?= (int)$pageIndex ?>">
<section class="book-page book-page-left">
  <div class="page-head"><div class="logo"><?= h($logoText) ?></div><div class="meta"><span>Medical Expires:</span><span>Class/Type Rating Expires:</span></div></div>
  <?= leftTemplate($chunk) ?>
  <?= leftTotalsBox($pageTotals, $previousTotals, $runningTotals) ?>
</section>
<section class="book-page book-page-right">
  <div class="page-head"><div></div><div class="logo right-logo"><?= h($logoText) ?></div></div>
  <?= rightTemplate($chunk) ?>
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
    return {w:540, h:190};
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
