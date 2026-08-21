<?php
declare(strict_types=1);

final class TheoryStudioException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message, $httpStatus);
    }
}
