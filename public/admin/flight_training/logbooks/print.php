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

function scaledBounds(array $columns, float $x, float $width): array
{
    $scale = $width / array_sum($columns);
    $bounds = array($x);
    foreach ($columns as $column) {
        $bounds[] = $bounds[array_key_last($bounds)] + ((float)$column * $scale);
    }
    return $bounds;
}

function renderCellBorders(array $cells): string
{
    $lines = array();
    $out = '';
    foreach ($cells as $cell) {
        $x1 = (float)$cell['x1'];
        $x2 = (float)$cell['x2'];
        $y1 = (float)$cell['y1'];
        $y2 = (float)$cell['y2'];
        $class = (string)($cell['class'] ?? 'sub');
        $borders = $cell['borders'] ?? array('top', 'right', 'bottom', 'left');
        foreach ($borders as $border) {
            $line = match ($border) {
                'top' => array($x1, $y1, $x2, $y1),
                'right' => array($x2, $y1, $x2, $y2),
                'bottom' => array($x1, $y2, $x2, $y2),
                'left' => array($x1, $y1, $x1, $y2),
                default => null,
            };
            if ($line === null) {
                continue;
            }
            $key = implode(':', array_map(static fn (float $value): string => number_format($value, 3, '.', ''), $line)) . ':' . $class;
            $lines[$key] = array($line, $class);
        }
        $text = (string)($cell['text'] ?? '');
        if ($text !== '') {
            $anchor = (string)($cell['anchor'] ?? 'middle');
            $textX = isset($cell['textX']) ? (float)$cell['textX'] : ($x1 + $x2) / 2;
            $textY = isset($cell['textY']) ? (float)$cell['textY'] : ($y1 + $y2) / 2;
            $out .= svgText($textX, $textY, $text, (string)($cell['textClass'] ?? 'body'), $anchor);
        }
    }
    foreach ($lines as [$line, $class]) {
        $out .= svgLine($line[0], $line[1], $line[2], $line[3], $class);
    }
    return $out;
}

function gridCell(array $bounds, int $colStart, int $colEnd, float $y1, float $y2, string $class = 'sub', string $text = '', string $textClass = 'body', ?float $textX = null, string $anchor = 'middle', ?array $borders = null): array
{
    return array(
        'x1' => $bounds[$colStart],
        'x2' => $bounds[$colEnd],
        'y1' => $y1,
        'y2' => $y2,
        'class' => $class,
        'text' => $text,
        'textClass' => $textClass,
        'textX' => $textX,
        'anchor' => $anchor,
        'borders' => $borders ?? array('top', 'right', 'bottom', 'left'),
    );
}

function bodyCells(array $bounds, float $bodyTop, float $rowH, int $rows, int $columns, ?array $footer = null): array
{
    $cells = array();
    $footerStartRow = $footer['startRow'] ?? $rows;
    $footerStartCol = $footer['startCol'] ?? $columns;
    $footerEndCol = $footer['endCol'] ?? $footerStartCol;
    for ($row = 0; $row < $rows; $row++) {
        $y1 = $bodyTop + ($row * $rowH);
        $y2 = $bodyTop + (($row + 1) * $rowH);
        for ($col = 0; $col < $columns; $col++) {
            if ($row >= $footerStartRow && $col >= $footerStartCol && $col < $footerEndCol) {
                continue;
            }
            $borders = array('right', 'bottom', 'left');
            if ($row === $footerStartRow - 1 && $col >= $footerStartCol && $col < $footerEndCol) {
                $borders = array_values(array_diff($borders, array('bottom')));
            }
            if ($row !== 0) {
                $borders[] = 'top';
            }
            $cells[] = gridCell($bounds, $col, $col + 1, $y1, $y2, 'row', '', 'body', null, 'middle', $borders);
        }
    }
    return $cells;
}

function leftTemplate(array $entries, array $pageTotals, array $previousTotals, array $runningTotals, string $logoText): string
{
    $columns = array(18, 12.25, 12.25, 12.25, 12.25, 27.5, 27.5, 12.75, 12.75, 16.5, 49, 13.5, 13.5);
    $gridX = 9.0;
    $gridY = 13.0;
    $gridW = 252.0;
    $gridH = 158.0;
    $bounds = scaledBounds($columns, $gridX, $gridW);
    $headerH = 13.5;
    $bodyTop = $gridY + $headerH;
    $rowHeight = ($gridH - $headerH) / 25;
    $footerStartRow = 22;
    $totalsY = $bodyTop + ($footerStartRow * $rowHeight);
    $centers = array_map(static fn (int $idx): float => ($bounds[$idx] + $bounds[$idx + 1]) / 2, array_keys($columns));
    $out = '<svg class="page-template left-template" viewBox="0 0 270 190" preserveAspectRatio="none">';
    $out .= svgText(14, 6.6, $logoText, 'logo-text', 'start');
    $out .= svgText(169, 6.6, 'Medical Expires:', 'micro');
    $out .= svgText(223, 6.6, 'Class/Type Rating Expires:', 'micro');
    $cells = array(
        gridCell($bounds, 0, 1, $gridY, $gridY + 4.5, 'main', '1', 'tiny'),
        gridCell($bounds, 1, 3, $gridY, $gridY + 4.5, 'main', '2', 'tiny'),
        gridCell($bounds, 3, 5, $gridY, $gridY + 4.5, 'main', '3', 'tiny'),
        gridCell($bounds, 5, 7, $gridY, $gridY + 4.5, 'main', '4', 'tiny'),
        gridCell($bounds, 7, 9, $gridY, $gridY + 4.5, 'main', '5', 'tiny'),
        gridCell($bounds, 9, 10, $gridY, $gridY + 4.5, 'main', '6', 'tiny'),
        gridCell($bounds, 10, 11, $gridY, $gridY + 4.5, 'main', '7', 'tiny'),
        gridCell($bounds, 11, 13, $gridY, $gridY + 4.5, 'main', '8', 'tiny'),
        gridCell($bounds, 0, 1, $gridY + 4.5, $bodyTop, 'main', 'Date', 'head'),
        gridCell($bounds, 1, 3, $gridY + 4.5, $gridY + 9.0, 'main', 'Departure', 'head'),
        gridCell($bounds, 3, 5, $gridY + 4.5, $gridY + 9.0, 'main', 'Arrival', 'head'),
        gridCell($bounds, 5, 7, $gridY + 4.5, $gridY + 9.0, 'main', 'Aircraft', 'head'),
        gridCell($bounds, 7, 9, $gridY + 4.5, $gridY + 9.0, 'main', 'Single Pilot', 'head'),
        gridCell($bounds, 9, 10, $gridY + 4.5, $bodyTop, 'main', 'Total Time', 'head'),
        gridCell($bounds, 10, 11, $gridY + 4.5, $bodyTop, 'main', 'Name PIC', 'head'),
        gridCell($bounds, 11, 13, $gridY + 4.5, $gridY + 9.0, 'main', 'Landings', 'head'),
    );
    foreach (array(1 => 'Place', 2 => 'Time', 3 => 'Place', 4 => 'Time', 5 => 'Type', 6 => 'Registration', 7 => 'SE', 8 => 'ME', 11 => 'Day', 12 => 'Night') as $idx => $label) {
        $cells[] = gridCell($bounds, $idx, $idx + 1, $gridY + 9.0, $bodyTop, 'main', $label, 'head');
    }
    $cells = array_merge($cells, bodyCells($bounds, $bodyTop, $rowHeight, 25, count($columns), array('startRow' => $footerStartRow, 'startCol' => 5, 'endCol' => 13)));
    foreach (array_slice($entries, 0, 25) as $idx => $entry) {
        $y = $gridY + $headerH + $idx * $rowHeight + ($rowHeight / 2) + 0.55;
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
    $totalRows = array(
        array('Total these pages', pval($pageTotals['total'] ?? 0), pval($pageTotals['day_landings'] ?? 0, 0), pval($pageTotals['night_landings'] ?? 0, 0)),
        array('Total from previous pages', pval($previousTotals['total'] ?? 0), pval($previousTotals['day_landings'] ?? 0, 0), pval($previousTotals['night_landings'] ?? 0, 0)),
        array('Total Time', pval($runningTotals['total'] ?? 0), pval($runningTotals['day_landings'] ?? 0, 0), pval($runningTotals['night_landings'] ?? 0, 0)),
    );
    $footerRowH = ($gridY + $gridH - $totalsY) / 3.0;
    foreach ($totalRows as $idx => $row) {
        $y1 = $totalsY + ($idx * $footerRowH);
        $y2 = $totalsY + (($idx + 1) * $footerRowH);
        $cells[] = gridCell($bounds, 5, 10, $y1, $y2, 'main', $row[0], 'micro');
        $cells[] = gridCell($bounds, 10, 11, $y1, $y2, 'main', $row[1], 'micro');
        $cells[] = gridCell($bounds, 11, 12, $y1, $y2, 'main', $row[2], 'micro');
        $cells[] = gridCell($bounds, 12, 13, $y1, $y2, 'main', $row[3], 'micro');
    }
    $out .= renderCellBorders($cells);
    return $out . '</svg>';
}

function rightTemplate(array $entries, array $pageTotals, array $previousTotals, array $runningTotals, string $logoText): string
{
    $columns = array(24.75, 24.75, 21.125, 21.125, 21.125, 21.125, 13.25, 13.25, 89.5);
    $gridX = 9.0;
    $gridY = 13.0;
    $gridW = 252.0;
    $gridH = 158.0;
    $bounds = scaledBounds($columns, $gridX, $gridW);
    $headerH = 13.5;
    $bodyTop = $gridY + $headerH;
    $rowHeight = ($gridH - $headerH) / 25;
    $footerStartRow = 22;
    $totalsY = $bodyTop + ($footerStartRow * $rowHeight);
    $centers = array_map(static fn (int $idx): float => ($bounds[$idx] + $bounds[$idx + 1]) / 2, array_keys($columns));
    $out = '<svg class="page-template right-template" viewBox="0 0 270 190" preserveAspectRatio="none">';
    $out .= svgText(258, 6.6, $logoText, 'logo-text', 'end');
    $cells = array(
        gridCell($bounds, 0, 2, $gridY, $gridY + 4.5, 'main', '9', 'tiny'),
        gridCell($bounds, 2, 6, $gridY, $gridY + 4.5, 'main', '10', 'tiny'),
        gridCell($bounds, 6, 8, $gridY, $gridY + 4.5, 'main', '11', 'tiny'),
        gridCell($bounds, 8, 9, $gridY, $gridY + 4.5, 'main', '12', 'tiny'),
        gridCell($bounds, 0, 2, $gridY + 4.5, $gridY + 9.0, 'main', 'Operational Condition Time', 'head'),
        gridCell($bounds, 2, 6, $gridY + 4.5, $gridY + 9.0, 'main', 'Pilot Function Time', 'head'),
        gridCell($bounds, 6, 8, $gridY + 4.5, $gridY + 9.0, 'main', 'Other Flying', 'head'),
        gridCell($bounds, 8, 9, $gridY + 4.5, $bodyTop, 'main', 'Remarks and Endorsements', 'head'),
    );
    foreach (array(0 => 'Night', 1 => 'IFR', 2 => 'PIC', 3 => 'Co-Pilot', 4 => 'Dual', 5 => 'Instructor', 6 => 'IF', 7 => 'NAV') as $idx => $label) {
        $cells[] = gridCell($bounds, $idx, $idx + 1, $gridY + 9.0, $bodyTop, 'main', $label, 'head');
    }
    $cells = array_merge($cells, bodyCells($bounds, $bodyTop, $rowHeight, 25, count($columns), array('startRow' => $footerStartRow, 'startCol' => 3, 'endCol' => 8)));
    foreach (array_slice($entries, 0, 25) as $idx => $entry) {
        $y = $gridY + $headerH + $idx * $rowHeight + ($rowHeight / 2) + 0.55;
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
            $textX = $colIdx === 8 ? $bounds[8] + 1.5 : $centers[$colIdx];
            $out .= svgText($textX, $y, (string)$value, $colIdx === 8 ? 'remarks-text' : 'body', $colIdx === 8 ? 'start' : 'middle');
        }
    }
    $rows = array(
        array('Total these pages', $pageTotals),
        array('Total from previous pages', $previousTotals),
        array('Total Time', $runningTotals),
    );
    $footerRowH = ($gridY + $gridH - $totalsY) / 3.0;
    foreach ($rows as $idx => $row) {
        $y1 = $totalsY + ($idx * $footerRowH);
        $y2 = $totalsY + (($idx + 1) * $footerRowH);
        $totals = $row[1];
        $cells[] = gridCell($bounds, 3, 4, $y1, $y2, 'main', $row[0], 'micro');
        $cells[] = gridCell($bounds, 4, 5, $y1, $y2, 'main', pval($totals['pic'] ?? 0), 'micro');
        $cells[] = gridCell($bounds, 5, 6, $y1, $y2, 'main', pval($totals['dual'] ?? 0), 'micro');
        $cells[] = gridCell($bounds, 6, 7, $y1, $y2, 'main', pval($totals['if'] ?? 0), 'micro');
        $cells[] = gridCell($bounds, 7, 8, $y1, $y2, 'main', pval($totals['nav'] ?? 0), 'micro');
    }
    $out .= renderCellBorders($cells);
    $sigY = 181.0;
    $out .= svgText(17, $sigY, 'I certify that the entries in this log are true', 'signature-text', 'start');
    $out .= svgLine(85, $sigY, 178, $sigY, 'sub');
    $out .= svgText(181, $sigY, '(Pilot\'s Signature).', 'signature-text', 'start');
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
.book-page-left{--table-x:var(--left-table-x)}.book-page-right{--table-x:var(--right-table-x)}
.page-template{position:absolute;inset:0;width:100%;height:100%;z-index:4;shape-rendering:crispEdges}
.page-template .row{stroke:#111;stroke-width:.13;vector-effect:non-scaling-stroke}
.page-template .sub{stroke:#111;stroke-width:.2;vector-effect:non-scaling-stroke}
.page-template .main{stroke:#111;stroke-width:.3;vector-effect:non-scaling-stroke}
.page-template .outer{stroke:#111;stroke-width:.42;vector-effect:non-scaling-stroke}
.page-template text{font-family:Arial,Helvetica,sans-serif;fill:#111;dominant-baseline:middle}
.page-template .logo-text{font-size:4.3px;letter-spacing:1.2px;font-weight:500}
.page-template .micro{font-size:1.85px;font-weight:500}
.page-template .tiny{font-size:2.55px;font-weight:700}
.page-template .head{font-size:2.35px;font-weight:700}
.page-template .body{font-size:1.9px;font-weight:400}
.page-template .remarks-text{font-size:1.65px;font-weight:400}
.page-template .signature-text{font-size:2.2px;font-weight:400}
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
  <?= leftTemplate($chunk, $pageTotals, $previousTotals, $runningTotals, $logoText) ?>
</section>
<section class="book-page book-page-right">
  <?= rightTemplate($chunk, $pageTotals, $previousTotals, $runningTotals, $logoText) ?>
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
