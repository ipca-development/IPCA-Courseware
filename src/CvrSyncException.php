<?php
declare(strict_types=1);

abstract class CvrSyncException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus,
        private readonly bool $retryable,
        private readonly bool $userActionRequired,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function userActionRequired(): bool
    {
        return $this->userActionRequired;
    }

    /** @return array<string,mixed> */
    public function payload(?string $requestId = null): array
    {
        return array_filter(array(
            'ok' => false,
            'error_code' => $this->errorCode,
            'error' => $this->getMessage(),
            'retryable' => $this->retryable,
            'user_action_required' => $this->userActionRequired,
            'request_id' => $requestId,
        ), static fn(mixed $value): bool => $value !== null);
    }
}

final class CvrTemporaryTechnicalFailure extends CvrSyncException
{
    public function __construct(
        string $message = 'Synchronization is temporarily unavailable.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 'TEMPORARY_TECHNICAL_FAILURE', 503, true, false, $previous);
    }
}

final class CvrDependencyNotReady extends CvrSyncException
{
    public function __construct(string $message = 'Required server linkage is not available yet.')
    {
        parent::__construct($message, 'DEPENDENCY_NOT_READY', 409, true, false);
    }
}

final class CvrImmutableConflict extends CvrSyncException
{
    public function __construct(string $message = 'Immutable synchronization identity conflicts with stored evidence.')
    {
        parent::__construct($message, 'IMMUTABLE_CONFLICT', 409, false, false);
    }
}

final class CvrAuthenticationRequired extends CvrSyncException
{
    public function __construct(string $message = 'CVR Unit authentication is required.', ?Throwable $previous = null)
    {
        parent::__construct($message, 'AUTHENTICATION_REQUIRED', 401, false, false, $previous);
    }
}

final class CvrUserCorrectionRequired extends CvrSyncException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'USER_CORRECTION_REQUIRED', 422, false, true);
    }
}

final class CvrTechnicalReviewRequired extends CvrSyncException
{
    public function __construct(string $message = 'Synchronization requires technical review.')
    {
        parent::__construct($message, 'TECHNICAL_REVIEW_REQUIRED', 500, false, false);
    }
}

function cvr_sync_request_id(array $payload): ?string
{
    $requestId = trim((string)($_SERVER['HTTP_X_IPCA_REQUEST_ID'] ?? $payload['request_id'] ?? ''));
    return $requestId !== '' ? substr($requestId, 0, 128) : null;
}
