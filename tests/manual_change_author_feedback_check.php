<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/publishing/BooksManualsChangeAuthorService.php';

function author_feedback_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$service = new BooksManualsChangeAuthorService(new PDO('sqlite::memory:'));
$method = new ReflectionMethod($service, 'correctableDraftFailures');

$missingNode = 'Accepted structure node 3.3 was not implemented.';
$corrections = $method->invoke($service, new RuntimeException($missingNode));
author_feedback_assert(
    $corrections === array($missingNode),
    'A missing accepted structure node was not classified as corrective retry feedback.'
);

$infrastructureFailure = $method->invoke(
    $service,
    new RuntimeException('OpenAI request timed out.')
);
author_feedback_assert(
    $infrastructureFailure === array(),
    'An infrastructure failure was incorrectly injected as manual-authoring guidance.'
);

echo "PASS: deterministic Author failures become governed retry corrections\n";
