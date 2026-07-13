<?php
declare(strict_types=1);

require_once __DIR__ . '/GarminProviderResult.php';

final class GarminSyncResult
{
    public function __construct(public GarminProviderResult $providerResult)
    {
    }

    public function ok(): bool
    {
        return $this->providerResult->ok;
    }

    /**
     * @return array<string,mixed>
     */
    public function data(): array
    {
        return $this->providerResult->data ?? array();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->providerResult->toArray();
    }
}
