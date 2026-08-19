<?php
declare(strict_types=1);

final class SchedulerApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly bool $retryable = false,
        public readonly bool $userActionRequired = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string,mixed> */
    public function payload(?string $requestId = null): array
    {
        return array_filter(array(
            'ok' => false,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'retryable' => $this->retryable,
            'user_action_required' => $this->userActionRequired,
            'request_id' => $requestId,
        ), static fn(mixed $value): bool => $value !== null);
    }

    public static function fromThrowable(Throwable $error): self
    {
        if ($error instanceof self) {
            return $error;
        }
        $message = trim($error->getMessage());
        $lower = strtolower($message);
        if (str_contains($lower, 'changed in another session')) {
            return new self(
                'reservation_changed',
                'This reservation changed in another session. Refresh it and try again.',
                409,
                false,
                true,
                $error
            );
        }
        if (str_contains($lower, 'claimed schedule slot')
            || str_contains($lower, 'cannot move after dispatch')
            || str_contains($lower, 'cannot be cancelled')
            || str_contains($lower, 'cannot be edited')
            || str_contains($lower, 'reservation is locked')) {
            return new self(
                'reservation_locked',
                'This reservation can no longer be changed.',
                409,
                false,
                true,
                $error
            );
        }
        if (str_contains($lower, 'not found')) {
            return new self('not_found', 'The requested reservation or resource was not found.', 404, false, false, $error);
        }
        if ($error instanceof InvalidArgumentException
            || ($error instanceof RuntimeException && !($error instanceof PDOException))) {
            return new self('validation_failed', $message !== '' ? $message : 'The request is not valid.', 422, false, true, $error);
        }
        return new self('server_error', 'The scheduler is temporarily unavailable.', 500, true, false, $error);
    }
}
