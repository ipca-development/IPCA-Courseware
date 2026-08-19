<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingFoundationService.php';

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$reflection = new ReflectionClass(ControlledPublishingFoundationService::class);
/** @var ControlledPublishingFoundationService $foundation */
$foundation = $reflection->newInstanceWithoutConstructor();
$cliBlocked = false;
try {
    $foundation->createNextDraftVersion(9, '6.0', 25, 'forged-token');
} catch (RuntimeException $e) {
    $cliBlocked = str_contains(
        $e->getMessage(),
        'cannot be created from CLI or automation'
    );
}
$assert($cliBlocked, 'Foundation successor cloning was not blocked under CLI.');

$fixtureDir = $root . '/tests/fixtures';
$command = implode(' ', array(
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/scripts/apply_manual_change_architect_sms_working_revision.php'),
    '--fixture-dir=' . escapeshellarg($fixtureDir),
    '--source=9',
    '--label=6.0',
    '--actor=25',
    '--approve-package=da4cef101b5dfb50c5559c05e5cad3f13394993104745854cd0cbeef37174058',
    '2>&1',
));
$output = array();
$exitCode = 0;
exec($command, $output, $exitCode);
$scriptOutput = implode("\n", $output);
$assert($exitCode !== 0, 'Manual Change Architect apply utility accepted a mutating invocation.');
$assert(
    str_contains($scriptOutput, 'permanently read-only'),
    'Manual Change Architect apply utility did not report its permanent read-only guard.'
);

if ($failures !== array()) {
    fwrite(STDERR, "Revision creation guard check: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Revision creation guard check: PASS\n";
echo "CLI and Manual Change Architect mutation paths are blocked\n";
