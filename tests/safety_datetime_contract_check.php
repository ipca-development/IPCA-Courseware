<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/safety/SafetySupport.php';

$checks = array(
    'empty event time is null' => SafetySupport::nullableUtc('') === null
        && SafetySupport::nullableUtc(null) === null,
    'iOS ISO-8601 Z becomes MySQL DATETIME(3)' =>
        SafetySupport::nullableUtc('2026-08-18T23:11:03Z') === '2026-08-18 23:11:03.000',
    'offset is converted to UTC' =>
        SafetySupport::nullableUtc('2026-08-18T16:11:03-07:00') === '2026-08-18 23:11:03.000',
    'website datetime-local is treated as UTC' =>
        SafetySupport::nullableUtc('2026-08-18T17:41') === '2026-08-18 17:41:00.000',
    'MySQL DATETIME(3) passes through as UTC' =>
        SafetySupport::nullableUtc('2026-08-18 23:11:03.000') === '2026-08-18 23:11:03.000',
);

$invalidThrew = false;
try {
    SafetySupport::nullableUtc('not-a-date');
} catch (SafetyException $e) {
    $invalidThrew = $e->errorCode === 'validation_error' && $e->httpStatus === 400;
}
$checks['invalid event time is a validation error'] = $invalidThrew;

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed safety datetime checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: safety datetime contract checks passed.' . PHP_EOL;
