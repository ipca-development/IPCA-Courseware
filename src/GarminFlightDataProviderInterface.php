<?php
declare(strict_types=1);

require_once __DIR__ . '/GarminDownloadResult.php';
require_once __DIR__ . '/GarminProviderResult.php';
require_once __DIR__ . '/GarminSyncResult.php';

interface GarminFlightDataProviderInterface
{
    public function getProviderName(): string;

    public function getProviderType(): string;

    /**
     * @return array<string,mixed>
     */
    public function getAuthenticationStatus(): array;

    public function isConfigured(): bool;

    public function isAuthenticated(): bool;

    public function requiresReauthentication(): bool;

    public function testConnection(): GarminProviderResult;

    public function runInitialSync(): GarminSyncResult;

    public function runIncrementalSync(?string $cursor): GarminSyncResult;

    public function runFullReconciliation(): GarminSyncResult;

    public function downloadFlightDataLog(string $flightDataLogUuid): GarminDownloadResult;

    public function disconnect(): void;
}
