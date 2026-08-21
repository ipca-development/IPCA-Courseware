<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/theory_studio/TheoryStudioIsolation.php';
require_once $root . '/src/theory_studio/TheoryContentStudioService.php';
require_once $root . '/src/theory_studio/TheoryHierarchySnapshot.php';
require_once $root . '/tests/helpers/theory_studio_fixture.php';

$pdo = theory_studio_test_pdo();
$before = theory_studio_seed_live($pdo);
$svc = new TheoryContentStudioService($pdo);

$attempts = array(
    'create course on live program' => static function () use ($svc): void {
        $svc->createCourse(1, array('title' => 'Should not exist', 'slug' => 'nope'));
    },
    'reorder live courses' => static function () use ($svc): void {
        $svc->reorderCourses(1, array(10));
    },
    'create lesson on live course' => static function () use ($svc): void {
        $svc->createLessons(10, array('title' => 'Injected'));
    },
    'reorder live lessons' => static function () use ($svc): void {
        $svc->reorderLessons(10, array(100));
    },
    'add slide on live lesson' => static function () use ($svc): void {
        $svc->addSlide(100);
    },
    'publish live program' => static function () use ($svc): void {
        $svc->publish(1);
    },
    'create draft from live / retire' => static function () use ($svc): void {
        $svc->mutateProtectedProgram(1);
    },
);

foreach ($attempts as $label => $fn) {
    $code = null;
    try {
        $fn();
        fwrite(STDERR, "FAIL: {$label} was allowed\n");
        exit(1);
    } catch (TheoryStudioException $e) {
        $code = $e->errorCode;
        if ($code !== 'LIVE_CONTENT_PROTECTED') {
            fwrite(STDERR, "FAIL: {$label} returned {$code} instead of LIVE_CONTENT_PROTECTED\n");
            exit(1);
        }
    }
    $after = theory_studio_live_fingerprint($pdo);
    if ($after !== $before) {
        fwrite(STDERR, "FAIL: {$label} mutated live rows\n");
        exit(1);
    }
    echo "PASS: {$label} => LIVE_CONTENT_PROTECTED, rows unchanged\n";
}

echo "Theory Content Studio live immutability: PASS\n";
