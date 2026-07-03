<?php
declare(strict_types=1);

@ini_set('memory_limit', '512M');

$path = '';
foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--replay=')) {
        $path = trim(substr($arg, strlen('--replay=')));
    }
}

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php scripts/validate_g3x_first_replay.php --replay=/path/to/replay.json\n");
    exit(1);
}

$raw = file_get_contents($path);
$payload = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($payload) || !isset($payload['samples']) || !is_array($payload['samples'])) {
    fwrite(STDERR, "Replay payload is not valid JSON with a samples array.\n");
    exit(1);
}

$samples = $payload['samples'];
$failures = array();
$count = count($samples);
if ($count < 2) {
    $failures[] = 'Replay must contain at least two samples.';
}

$previousT = null;
$maxDtError = 0.0;
$duplicateUtc = array();
$seenUtc = array();
$rollCount = 0;
$ahrsPrimary = 0;
$departureAltitudes = array();
$arrivalAltitudes = array();

foreach ($samples as $index => $sample) {
    if (!is_array($sample)) {
        $failures[] = "Sample {$index} is not an object.";
        continue;
    }
    $t = isset($sample['replay_time_s']) && is_numeric($sample['replay_time_s'])
        ? (float)$sample['replay_time_s']
        : (isset($sample['t']) && is_numeric($sample['t']) ? (float)$sample['t'] : null);
    if ($t === null) {
        $failures[] = "Sample {$index} is missing replay_time_s/t.";
        continue;
    }
    if ($previousT !== null) {
        if ($t <= $previousT) {
            $failures[] = "Sample {$index} is not monotonic: {$previousT} -> {$t}.";
        }
        $maxDtError = max($maxDtError, abs(($t - $previousT) - 0.1));
    }
    $previousT = $t;

    if (isset($sample['time_utc']) && $sample['time_utc'] !== '') {
        $utc = (string)$sample['time_utc'];
        if (isset($seenUtc[$utc])) {
            $duplicateUtc[$utc] = true;
        }
        $seenUtc[$utc] = true;
    }

    if (isset($sample['roll_deg']) && is_numeric($sample['roll_deg'])) {
        $rollCount++;
    }

    foreach (array('heading_source', 'heading_owner', 'raw_attitude_source') as $field) {
        if (isset($sample[$field]) && str_contains(strtolower((string)$sample[$field]), 'ahrs')) {
            $ahrsPrimary++;
            break;
        }
    }

    $alt = isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft']) ? (float)$sample['altitude_ft'] : null;
    if ($alt !== null && $t <= 90.0) {
        $departureAltitudes[] = $alt;
    }
    if ($alt !== null && $t >= max(0.0, ($previousT ?? 0.0) - 180.0)) {
        $arrivalAltitudes[] = $alt;
    }
}

if ($maxDtError > 0.002) {
    $failures[] = 'Replay is not clean 10 Hz. Max dt error: ' . round($maxDtError, 4) . 's.';
}
if ($duplicateUtc !== array()) {
    $failures[] = 'time_utc contains duplicate values: ' . count($duplicateUtc);
}
if ($rollCount !== $count) {
    $failures[] = "roll_deg populated for {$rollCount}/{$count} samples.";
}
if ($ahrsPrimary > 0) {
    $failures[] = "AHRS appears in primary replay state for {$ahrsPrimary} samples.";
}

$departureMedian = $departureAltitudes ? median($departureAltitudes) : null;
if ($departureMedian === null || $departureMedian < 1330.0 || $departureMedian > 1375.0) {
    $failures[] = 'Departure altitude median outside expected F70 range: ' . var_export($departureMedian, true);
}

$arrivalSlice = array_slice(array_values(array_filter(array_map(
    static fn(array $sample): ?float => isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft']) ? (float)$sample['altitude_ft'] : null,
    $samples
), static fn($value): bool => $value !== null)), -300);
$arrivalMedian = $arrivalSlice ? median($arrivalSlice) : null;
if ($arrivalMedian === null || $arrivalMedian < -150.0 || $arrivalMedian > -80.0) {
    $failures[] = 'Arrival altitude median outside expected KTRM range: ' . var_export($arrivalMedian, true);
}

$result = array(
    'ok' => $failures === array(),
    'sample_count' => $count,
    'max_dt_error_s' => round($maxDtError, 4),
    'roll_populated_count' => $rollCount,
    'ahrs_primary_count' => $ahrsPrimary,
    'duplicate_time_utc_count' => count($duplicateUtc),
    'departure_altitude_median_ft' => $departureMedian !== null ? round($departureMedian, 1) : null,
    'arrival_altitude_median_ft' => $arrivalMedian !== null ? round($arrivalMedian, 1) : null,
    'failures' => $failures,
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === array() ? 0 : 1);

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    if ($count % 2 === 1) {
        return (float)$values[$middle];
    }
    return ((float)$values[$middle - 1] + (float)$values[$middle]) / 2.0;
}
